<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Infrastructure\Jobs;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\SwissEbill\Application\Commands\TransmitInvoiceToEbillCommand;
use Modules\SwissEbill\Application\Commands\TransmitInvoiceToEbillHandler;
use Modules\SwissEbill\Domain\Enums\EbillParticipantStatus;
use Modules\SwissEbill\Domain\Exceptions\EbillException;
use Modules\SwissEbill\Domain\Repositories\EbillSettingRepositoryInterface;

/**
 * "Send automatically when the invoice is issued": queues the eBill of a freshly posted invoice for a customer who is enrolled and
 * prefers electronic delivery. An invoice that cannot be sent (missing datum) is logged and left for the user; it never blocks issuing.
 */
final class AutoTransmitInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly string $invoiceId, public readonly string $tenantId) {}

    public function handle(TenantContext $tenants, EbillSettingRepositoryInterface $settings, TransmitInvoiceToEbillHandler $transmit): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $tenants->runWithTenant($tenant, function () use ($settings, $transmit): void {
            $setting = $settings->current();
            $invoice = Invoice::query()->find($this->invoiceId);
            $contact = $invoice?->contact_id !== null ? Contact::query()->find($invoice->contact_id) : null;

            if ($setting === null || ! $setting->isReadyToTransmit() || ! $setting->auto_send_on_invoice_issue || $invoice === null || $contact === null) {
                return;
            }

            if ($contact->ebill_status !== EbillParticipantStatus::Enrolled->value || ! $contact->ebill_opt_in_preferred) {
                return;
            }

            try {
                $transmit->handle(new TransmitInvoiceToEbillCommand($invoice->id, null, null, 'system'));
            } catch (EbillException $e) {
                Log::info('Swiss eBill auto-send skipped', ['invoice_id' => $invoice->id, 'code' => $e->errorCode(), 'message' => $e->getMessage()]);
            }
        });
    }
}
