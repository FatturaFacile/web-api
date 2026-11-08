<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Enterprise\AuditLedger\Services\AuditLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Workflow\Models\WorkflowAuditEntry;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;

/**
 * Append-only, hash-chained log of what happened to a workflow instance. Each entry signs the previous signature, the
 * instance, the event and a canonical form of its payload (keys sorted, so the stored JSON and the hash agree whatever
 * order the database returns). Entries carry a per-instance sequence taken under a lock on the instance row, so the chain
 * has one order even for events recorded in the same instant.
 */
final class WorkflowAuditLedgerService
{
    private const string Genesis = 'GENESIS';

    /**
     * Inject the collaborators.
     */
    public function __construct(
        private readonly ?AuditLedgerService $enterpriseAuditService = null
    ) {}

    /**
     * Appends an entry to the chain of the instance.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(
        WorkflowInstance $instance,
        string $eventType,
        array $payload,
        ?WorkflowInstanceStep $step = null,
        ?string $actorUserId = null
    ): WorkflowAuditEntry {
        $entry = DB::transaction(function () use ($instance, $eventType, $payload, $step, $actorUserId): WorkflowAuditEntry {
            // One writer at a time per instance: the sequence and the previous signature are read under this lock.
            WorkflowInstance::withoutGlobalScopes()->whereKey($instance->id)->lockForUpdate()->value('id');

            $last = WorkflowAuditEntry::withoutGlobalScopes()
                ->where('workflow_instance_id', $instance->id)
                ->orderByRaw('sequence DESC NULLS LAST')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            $payload = $this->canonical($payload);

            return WorkflowAuditEntry::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $instance->tenant_id,
                'company_id' => $instance->company_id,
                'workflow_instance_id' => $instance->id,
                'workflow_instance_step_id' => $step?->id,
                'event_type' => $eventType,
                'event_payload' => $payload,
                'actor_user_id' => $actorUserId ?? auth()->id(),
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 500),
                'hmac_signature' => $this->sign($last?->hmac_signature ?? self::Genesis, (string) $instance->id, $eventType, $payload),
                'sequence' => ($last->sequence ?? 0) + 1,
                'created_at' => now(),
            ]);
        });

        // Forward to central EnterpriseAuditLedger if available
        if ($this->enterpriseAuditService !== null) {
            $this->enterpriseAuditService->record(
                action: strtoupper($eventType),
                model: $instance,
                payloadBefore: null,
                payloadAfter: $payload,
                moduleCode: 'WORKFLOW'
            );
        }

        return $entry;
    }

    /**
     * Recomputes the chain of an instance. Entries written before the sequence existed were signed over the payload as
     * the application held it then and cannot be checked again: they are counted as legacy and only anchor the chain.
     *
     * @return array{is_intact: bool, entries: int, verified: int, legacy_unverifiable: int, first_broken_sequence: ?int}
     */
    public function verify(WorkflowInstance $instance): array
    {
        $entries = WorkflowAuditEntry::withoutGlobalScopes()
            ->where('workflow_instance_id', $instance->id)
            ->orderByRaw('sequence ASC NULLS FIRST')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $previous = self::Genesis;
        $verified = 0;
        $legacy = 0;
        $broken = null;
        $expectedSequence = 1;

        foreach ($entries as $entry) {
            if ($entry->sequence === null) {
                $legacy++;
                $previous = (string) $entry->hmac_signature;

                continue;
            }

            $expected = $this->sign($previous, (string) $instance->id, (string) $entry->event_type, $this->canonical((array) $entry->event_payload));

            if ((int) $entry->sequence !== $expectedSequence || ! hash_equals($expected, (string) $entry->hmac_signature)) {
                $broken ??= (int) $entry->sequence;
            } else {
                $verified++;
            }

            $previous = (string) $entry->hmac_signature;
            $expectedSequence = (int) $entry->sequence + 1;
        }

        return [
            'is_intact' => $broken === null,
            'entries' => $entries->count(),
            'verified' => $verified,
            'legacy_unverifiable' => $legacy,
            'first_broken_sequence' => $broken,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sign(string $previousSignature, string $instanceId, string $eventType, array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return hash_hmac('sha256', $previousSignature.'|'.$instanceId.'|'.$eventType.'|'.$json, (string) config('app.key', 'workflow_audit_salt'));
    }

    /**
     * Keys sorted at every depth.
     *
     * @param  array<int|string, mixed>  $value
     * @return array<int|string, mixed>
     */
    private function canonical(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
