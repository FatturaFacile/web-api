<?php

declare(strict_types=1);

namespace App\Actions\Sdi;

use App\Contracts\SdiProvider;
use App\Exceptions\SdiProviderException;
use App\Models\EiOutboundLog;
use App\Models\Invoice;
use App\Models\InvoiceSdiMetadata;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Tenancy\TenantPreferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SendInvoiceToSdi
{
    public function __construct(
        private readonly SdiProvider $provider,
        private readonly FatturaPaBuilderService $builder,
        private readonly DocumentEventRecorder $events,
        private readonly TenantPreferences $preferences,
    ) {}

    /**
     * Build the FatturaPA XML, transmit it through the SDI provider and record the exchange.
     * Provider failures are logged and flagged on the invoice before the exception is rethrown.
     *
     * @throws ValidationException when the invoice is not eligible for transmission
     * @throws SdiProviderException when the provider rejects the transmission
     */
    public function execute(Invoice $invoice): InvoiceSdiMetadata
    {
        $this->assertEligible($invoice);

        $invoice->loadMissing(['tenant', 'contact.addresses', 'items', 'sdiMetadata']);

        $xml = $this->builder->buildFatturaPaXml($invoice);
        $fileName = $invoice->sdiMetadata?->file_name ?? $this->fileName($invoice);
        $format = ($invoice->contact?->sdi_code && strlen(trim($invoice->contact->sdi_code)) === 6) ? 'FPA12' : 'FPR12';

        try {
            $result = $this->provider->send($fileName, $xml);
        } catch (SdiProviderException $e) {
            $this->recordFailure($invoice, $fileName, $xml, $format, $e);

            throw $e;
        }

        return DB::transaction(function () use ($invoice, $fileName, $xml, $format, $result): InvoiceSdiMetadata {
            $metadata = $invoice->sdiMetadata()->updateOrCreate(['invoice_id' => $invoice->id], [
                'transmission_format' => $format,
                'sdi_status' => 'sending',
                'sdi_identifier' => $result['uuid'],
                'file_name' => $fileName,
                'xml_content' => $xml,
                'sent_at' => now(),
                'delivered_at' => null,
            ]);

            $invoice->sdiUuidLinks()->create([
                'tenant_id' => $invoice->tenant_id,
                'provider' => $this->provider->name(),
                'sdi_uuid' => $result['uuid'],
            ]);

            EiOutboundLog::create([
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'provider' => $this->provider->name(),
                'action' => 'send',
                'status' => 'success',
                'sdi_uuid' => $result['uuid'],
                'file_name' => $fileName,
                'response' => $result['response'],
            ]);

            $this->events->record($invoice, 'sent_to_sdi', ['sdi_uuid' => $result['uuid'], 'file_name' => $fileName]);

            return $metadata;
        });
    }

    private function assertEligible(Invoice $invoice): void
    {
        if (! (bool) $this->preferences->get('sdi', 'send_enabled', true)) {
            throw ValidationException::withMessages(['invoice' => __('sdi.sending_disabled')]);
        }

        if ($invoice->jurisdiction !== 'IT' || in_array($invoice->invoice_type, ['proforma', 'ch_qr_bill'], true)) {
            throw ValidationException::withMessages(['invoice' => __('sdi.not_eligible')]);
        }

        $status = $invoice->sdiMetadata()->value('sdi_status');

        if (in_array($status, ['sending', 'delivered', 'accepted'], true)) {
            throw ValidationException::withMessages(['invoice' => __('sdi.already_sent', ['status' => $status])]);
        }
    }

    /**
     * SDI file name: IT<VAT>_<5-char progressive>.xml, derived from the invoice sequence when available.
     */
    private function fileName(Invoice $invoice): string
    {
        $progressive = $invoice->sequential_number !== null
            ? str_pad(strtoupper(base_convert((string) $invoice->sequential_number, 10, 36)), 5, '0', STR_PAD_LEFT)
            : strtoupper(substr(md5($invoice->id), 0, 5));

        return sprintf('IT%s_%s.xml', preg_replace('/\D/', '', (string) $invoice->tenant?->vat_number) ?: '99999999999', substr($progressive, -5));
    }

    private function recordFailure(Invoice $invoice, string $fileName, string $xml, string $format, SdiProviderException $e): void
    {
        $invoice->sdiMetadata()->updateOrCreate(['invoice_id' => $invoice->id], [
            'transmission_format' => $format,
            'sdi_status' => 'failed',
            'file_name' => $fileName,
            'xml_content' => $xml,
        ]);

        EiOutboundLog::create([
            'tenant_id' => $invoice->tenant_id,
            'invoice_id' => $invoice->id,
            'provider' => $this->provider->name(),
            'action' => 'send',
            'status' => 'failed',
            'file_name' => $fileName,
            'error' => mb_substr($e->getMessage(), 0, 1000),
        ]);

        $this->events->record($invoice, 'sdi_send_failed', ['error' => $e->getMessage()]);
    }
}
