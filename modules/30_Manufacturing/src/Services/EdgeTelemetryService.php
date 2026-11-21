<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgEdgeGateway;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgMachineStateEvent;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;

/**
 * Ingestion of machine state events pushed by edge gateways (OPC-UA, Modbus TCP, MQTT, CNC adapters).
 *
 * Requests are authenticated by the HMAC-SHA256 signature of the raw body with the per-gateway secret. Every event
 * carries an `event_uuid` (idempotency key), so the store-and-forward buffer of a gateway can resend batches safely.
 * An event updates the live state of the machine only when it is newer than the state already held, and is linked to
 * the production order operation running on the machine at that moment.
 */
final class EdgeTelemetryService
{
    /**
     * The active gateway whose secret signs the body, null when the gateway is unknown or the signature differs.
     */
    public function authenticate(?string $gatewayId, ?string $signature, string $body): ?MfgEdgeGateway
    {
        if ($gatewayId === null || $signature === null || ! Str::isUuid($gatewayId)) {
            return null;
        }

        $gateway = MfgEdgeGateway::withoutGlobalScopes()->where('is_active', true)->whereNull('deleted_at')->find($gatewayId);
        if (! $gateway instanceof MfgEdgeGateway) {
            return null;
        }

        return hash_equals(self::sign($gateway->secret, $body), strtolower($signature)) ? $gateway : null;
    }

    /**
     * The hex HMAC-SHA256 signature a gateway sends in `X-Signature`.
     */
    public static function sign(string $secret, string $body): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    /**
     * Stores a batch of events.
     *
     * @param  list<array{event_uuid: string, machine_id: string, state: string, occurred_at: string, payload?: array<string, mixed>|null}>  $events
     * @return array{accepted: int, duplicates: int, rejected: list<string>}
     */
    public function ingest(MfgEdgeGateway $gateway, array $events): array
    {
        $tenantId = (string) $gateway->tenant_id;
        $accepted = 0;
        $duplicates = 0;
        $rejected = [];

        // Oldest first, so the live state ends on the newest event of the batch.
        usort($events, static fn (array $a, array $b): int => strtotime($a['occurred_at']) <=> strtotime($b['occurred_at']));

        DB::transaction(function () use ($gateway, $tenantId, $events, &$accepted, &$duplicates, &$rejected): void {
            foreach ($events as $event) {
                if (MfgMachineStateEvent::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('event_uuid', $event['event_uuid'])->exists()) {
                    $duplicates++;

                    continue;
                }

                $machine = MfgMachineCenter::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->find($event['machine_id']);
                if (! $machine instanceof MfgMachineCenter) {
                    $rejected[] = $event['event_uuid'];

                    continue;
                }

                $occurredAt = Carbon::parse($event['occurred_at']);
                $operation = MfgProductionOrderOperation::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('machine_center_id', $machine->id)
                    ->whereNotNull('started_at')->whereNull('completed_at')->where('started_at', '<=', $occurredAt)->orderByDesc('started_at')->first();

                MfgMachineStateEvent::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'gateway_id' => $gateway->id,
                    'machine_id' => $machine->id,
                    'event_uuid' => $event['event_uuid'],
                    'state' => $event['state'],
                    'occurred_at' => $occurredAt,
                    'production_order_operation_id' => $operation?->id,
                    'payload' => $event['payload'] ?? null,
                ]);

                if ($machine->state_since === null || $occurredAt->gte($machine->state_since)) {
                    $machine->forceFill(['current_state' => $event['state'], 'state_since' => $occurredAt])->save();
                }
                $accepted++;
            }

            $gateway->forceFill(['last_seen_at' => now()])->save();
        });

        return ['accepted' => $accepted, 'duplicates' => $duplicates, 'rejected' => $rejected];
    }
}
