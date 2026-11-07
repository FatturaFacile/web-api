<?php

declare(strict_types=1);

namespace App\Actions\Sdi;

use App\Models\EiInboundLog;
use App\Models\Invoice;
use App\Models\InvoiceSdiMetadata;
use App\Models\SdiUuidLink;
use App\Models\Tenant;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessSdiNotification
{
    /**
     * SDI receipt codes mapped to the invoice SDI status.
     * RC delivered, NS rejected by SDI, MC and AT not delivered (the invoice is valid and waits in the tax drawer of the
     * customer), DT deadline passed (treated as delivered), NEC1 accepted by the customer, NEC2 rejected by the customer.
     *
     * @var array<string, string>
     */
    private const STATUS_BY_EVENT = [
        'RC' => 'delivered',
        'NS' => 'rejected',
        'MC' => 'not_delivered',
        'AT' => 'not_delivered',
        'DT' => 'delivered',
        'NEC1' => 'accepted',
        'NEC2' => 'rejected',
    ];

    /**
     * Precedence of the SDI statuses a notification can reach. A notification only moves an invoice forward: a status
     * replaces the current one only when its rank is higher, so receipts arriving late or out of order (an MC or AT
     * after the RC, an RC after the outcome of the customer) never move it back.
     *
     *  1 sending        transmitted, waiting for SDI
     *  2 not_delivered  SDI could not deliver (MC, AT): the file is valid, delivery may still be confirmed later
     *  3 delivered      delivered to the customer (RC) or deadline passed (DT)
     *  4 accepted       outcome of the customer (NEC1), final
     *  4 rejected       discarded by SDI (NS) or refused by the customer (NEC2), final
     *
     * Statuses outside this list (draft, failed) were never handed to SDI and rank 0.
     *
     * @var array<string, int>
     */
    private const STATUS_PRECEDENCE = [
        'sending' => 1,
        'not_delivered' => 2,
        'delivered' => 3,
        'accepted' => 4,
        'rejected' => 4,
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
     * Process one provider notification. Delivery is idempotent: a notification already processed returns its log, one
     * logged but never processed (a previous attempt failed) is processed again. The log is finished in the same
     * transaction that changes the invoice, so a failure leaves it unprocessed for the next delivery.
     *
     * @param  array{provider: string, uuid: string, event_type: string, message?: ?string}  $data
     */
    public function execute(array $data): EiInboundLog
    {
        $fingerprint = hash('sha256', implode('|', [$data['provider'], $data['uuid'], $data['event_type'], $data['message'] ?? '']));

        $link = SdiUuidLink::withoutGlobalScopes()->where('provider', $data['provider'])->where('sdi_uuid', $data['uuid'])->first();

        $log = EiInboundLog::createOrFirst(['fingerprint' => $fingerprint], [
            'tenant_id' => $link?->tenant_id,
            'invoice_id' => $link?->invoice_id,
            'provider' => $data['provider'],
            'event_type' => $data['event_type'],
            'sdi_uuid' => $data['uuid'],
            'payload' => $data,
        ]);

        if ($log->processed_at !== null) {
            return $log;
        }

        if ($link === null) {
            return $this->finishOnce($log, 'ignored', __('sdi.unknown_uuid'));
        }

        $status = self::STATUS_BY_EVENT[$data['event_type']] ?? null;

        if ($status === null) {
            return $this->finishOnce($log, 'ignored', __('sdi.unsupported_event'));
        }

        $tenant = Tenant::find($link->tenant_id);

        return $this->tenantContext->runWithTenant($tenant, fn (): EiInboundLog => DB::transaction(function () use ($link, $log, $status, $data): EiInboundLog {
            $log = EiInboundLog::query()->whereKey($log->getKey())->lockForUpdate()->firstOrFail();

            if ($log->processed_at !== null) {
                return $log;
            }

            $invoice = Invoice::findOrFail($link->invoice_id);
            $metadata = InvoiceSdiMetadata::query()->where('invoice_id', $invoice->id)->lockForUpdate()->first();

            if ($metadata === null || in_array($metadata->sdi_status, self::TERMINAL_STATUSES, true)) {
                return $this->finish($log, 'ignored', __('sdi.status_final'));
            }

            if ($metadata->sdi_identifier !== $data['uuid']) {
                Log::warning('SDI notification for a transmission that is not the current one of the invoice.', [
                    'invoice_id' => $invoice->id,
                    'provider' => $data['provider'],
                    'notification_uuid' => $data['uuid'],
                    'current_uuid' => $metadata->sdi_identifier,
                    'event_type' => $data['event_type'],
                ]);

                return $this->finish($log, 'ignored', __('sdi.not_current_transmission'));
            }

            if ((self::STATUS_PRECEDENCE[$status] ?? 0) <= (self::STATUS_PRECEDENCE[$metadata->sdi_status] ?? 0)) {
                return $this->finish($log, 'ignored', __('sdi.status_superseded', ['status' => $metadata->sdi_status]));
            }

            $metadata->update([
                'sdi_status' => $status,
                'delivered_at' => $status === 'delivered' ? now() : $metadata->delivered_at,
            ]);

            $this->events->record($invoice, 'sdi_notification', [
                'event_type' => $data['event_type'],
                'sdi_status' => $status,
                'message' => $data['message'] ?? null,
                'courtesy_copy_required' => $status === 'not_delivered',
            ]);

            return $this->finish($log, 'processed');
        }));
    }

    /**
     * Finish a log outside the invoice transaction, unless a concurrent delivery finished it first.
     */
    private function finishOnce(EiInboundLog $log, string $status, string $error): EiInboundLog
    {
        EiInboundLog::query()->whereKey($log->getKey())->whereNull('processed_at')
            ->update(['status' => $status, 'error' => $error, 'processed_at' => now()]);

        return $log->refresh();
    }

    private function finish(EiInboundLog $log, string $status, ?string $error = null): EiInboundLog
    {
        $log->update(['status' => $status, 'error' => $error, 'processed_at' => now()]);

        return $log;
    }
}
