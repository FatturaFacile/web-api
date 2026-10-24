<?php

declare(strict_types=1);

namespace App\Actions\Sdi;

use App\Models\EiInboundLog;
use App\Models\Invoice;
use App\Models\SdiUuidLink;
use App\Models\Tenant;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class ProcessSdiNotification
{
    /**
     * SDI receipt codes mapped to the invoice SDI status.
     * RC delivered, NS rejected by SDI, MC delivery failed, DT deadline passed (treated as delivered),
     * NEC1 accepted by the customer, NEC2 rejected by the customer.
     *
     * @var array<string, string>
     */
    private const STATUS_BY_EVENT = [
        'RC' => 'delivered',
        'NS' => 'rejected',
        'MC' => 'failed',
        'DT' => 'delivered',
        'NEC1' => 'accepted',
        'NEC2' => 'rejected',
    ];

    /**
     * Statuses that can no longer be overwritten by a late notification.
     *
     * @var array<int, string>
     */
    private const TERMINAL_STATUSES = ['accepted', 'rejected'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentEventRecorder $events,
    ) {}

    /**
     * Process one provider notification. Delivery is idempotent: a repeated notification returns the stored log.
     *
     * @param  array{provider: string, uuid: string, event_type: string, message?: ?string}  $data
     */
    public function execute(array $data): EiInboundLog
    {
        $fingerprint = hash('sha256', implode('|', [$data['provider'], $data['uuid'], $data['event_type'], $data['message'] ?? '']));

        $existing = EiInboundLog::where('fingerprint', $fingerprint)->first();

        if ($existing !== null) {
            return $existing;
        }

        $link = SdiUuidLink::withoutGlobalScopes()->where('provider', $data['provider'])->where('sdi_uuid', $data['uuid'])->first();

        $log = EiInboundLog::create([
            'tenant_id' => $link?->tenant_id,
            'invoice_id' => $link?->invoice_id,
            'provider' => $data['provider'],
            'event_type' => $data['event_type'],
            'sdi_uuid' => $data['uuid'],
            'fingerprint' => $fingerprint,
            'payload' => $data,
        ]);

        if ($link === null) {
            return $this->finish($log, 'ignored', __('sdi.unknown_uuid'));
        }

        $status = self::STATUS_BY_EVENT[$data['event_type']] ?? null;

        if ($status === null) {
            return $this->finish($log, 'ignored', __('sdi.unsupported_event'));
        }

        $tenant = Tenant::find($link->tenant_id);

        return $this->tenantContext->runWithTenant($tenant, fn (): EiInboundLog => DB::transaction(function () use ($link, $log, $status, $data): EiInboundLog {
            $invoice = Invoice::findOrFail($link->invoice_id);
            $metadata = $invoice->sdiMetadata;

            if ($metadata === null || in_array($metadata->sdi_status, self::TERMINAL_STATUSES, true)) {
                return $this->finish($log, 'ignored', __('sdi.status_final'));
            }

            $metadata->update([
                'sdi_status' => $status,
                'delivered_at' => $status === 'delivered' ? now() : $metadata->delivered_at,
            ]);

            $this->events->record($invoice, 'sdi_notification', [
                'event_type' => $data['event_type'],
                'sdi_status' => $status,
                'message' => $data['message'] ?? null,
            ]);

            return $this->finish($log, 'processed');
        }));
    }

    private function finish(EiInboundLog $log, string $status, ?string $error = null): EiInboundLog
    {
        $log->update(['status' => $status, 'error' => $error, 'processed_at' => now()]);

        return $log;
    }
}
