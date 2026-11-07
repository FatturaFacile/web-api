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
use Modules\SDI\Services\SdiFileNamer;
use Modules\SDI\Services\SdiPreflight;
use Throwable;

class SendInvoiceToSdi
{
    /**
     * Statuses of a file SDI already has, or is receiving right now. A file SDI could not deliver (not_delivered) is
     * still a valid invoice: it stays in the tax drawer of the customer.
     *
     * @var list<string>
     */
    private const array TRANSMITTED_STATUSES = ['sending', 'delivered', 'accepted', 'not_delivered'];

    /**
     * A claim without an SDI identifier older than this belongs to a request that died while transmitting.
     */
    private const int STALE_CLAIM_MINUTES = 15;

    public function __construct(
        private readonly SdiProvider $provider,
        private readonly FatturaPaBuilderService $builder,
        private readonly DocumentEventRecorder $events,
        private readonly TenantPreferences $preferences,
        private readonly SdiFileNamer $fileNames,
        private readonly SdiPreflight $preflight,
    ) {}

    /**
     * Build the FatturaPA XML, transmit it through the SDI provider and record the exchange.
     *
     * The invoice is claimed first (status sending, no SDI identifier yet) in a short transaction holding the invoice
     * row lock, so a double click or two workers cannot transmit the same invoice twice. A provider failure turns the
     * claim into failed, is logged and flagged on the invoice before the exception is rethrown.
     *
     * @throws ValidationException when the invoice is not eligible or is already transmitted or being transmitted
     * @throws SdiProviderException when the provider rejects the transmission
     */
    public function execute(Invoice $invoice): InvoiceSdiMetadata
    {
        $this->assertEligible($invoice);
        $this->assertNotTransmitted($invoice->sdiMetadata()->first());

        $invoice->loadMissing(['tenant', 'contact.addresses', 'items', 'sdiMetadata', 'declarationOfIntent', 'sourcePassiveInvoice', 'relatedInvoice']);

        $this->preflight->assertReady($invoice);

        $format = $this->builder->transmissionFormat($invoice);
        ['file_name' => $fileName, 'progressive' => $progressive, 'previous' => $previous] = $this->claim($invoice, $format);
        $xml = null;

        try {
            $xml = $this->builder->buildFatturaPaXml($invoice, $progressive);
            $result = $this->provider->send($fileName, $xml);
        } catch (SdiProviderException $e) {
            $this->recordFailure($invoice, $fileName, $xml, $format, $e);

            throw $e;
        } catch (Throwable $e) {
            $this->releaseClaim($invoice, $previous);

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
                'transmission_claimed_at' => null,
            ]);

            $invoice->sdiUuidLinks()->firstOrCreate([
                'provider' => $this->provider->name(),
                'sdi_uuid' => $result['uuid'],
            ], [
                'tenant_id' => $invoice->tenant_id,
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
    }

    /**
     * A claim left behind by a request that died before the provider answered may be taken over once it is stale.
     */
    private function assertNotTransmitted(?InvoiceSdiMetadata $metadata): void
    {
        $status = $metadata?->sdi_status;

        if ($metadata === null || ! in_array($status, self::TRANSMITTED_STATUSES, true)) {
            return;
        }

        $isStaleClaim = $status === 'sending'
            && $metadata->sdi_identifier === null
            && ($metadata->transmission_claimed_at === null || $metadata->transmission_claimed_at->lt(now()->subMinutes(self::STALE_CLAIM_MINUTES)));

        if (! $isStaleClaim) {
            throw ValidationException::withMessages(['invoice' => __('sdi.already_sent', ['status' => $status])]);
        }
    }

    /**
     * Take the invoice for this request: under the invoice row lock the status is checked again, the file name is
     * chosen and the metadata is marked sending without an SDI identifier. The transaction ends before the provider is
     * called, so no lock is held across the network.
     *
     * @return array{file_name: string, progressive: string, previous: array<string, mixed>|null}
     */
    private function claim(Invoice $invoice, string $format): array
    {
        return DB::transaction(function () use ($invoice, $format): array {
            $invoice->newQuery()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail(['id']);

            $metadata = InvoiceSdiMetadata::query()->where('invoice_id', $invoice->id)->lockForUpdate()->first();

            $this->assertNotTransmitted($metadata);

            ['file_name' => $fileName, 'progressive' => $progressive] = $this->transmissionName($invoice, $metadata);
            $previous = $metadata?->only(['sdi_status', 'sdi_identifier', 'file_name', 'transmission_claimed_at']);

            $claimed = $invoice->sdiMetadata()->updateOrCreate(['invoice_id' => $invoice->id], [
                'transmission_format' => $format,
                'sdi_status' => 'sending',
                'sdi_identifier' => null,
                'file_name' => $fileName,
                'transmission_claimed_at' => now(),
            ]);

            $invoice->setRelation('sdiMetadata', $claimed);

            return ['file_name' => $fileName, 'progressive' => $progressive, 'previous' => $previous];
        });
    }

    /**
     * Give the invoice back as it was when nothing reached the provider.
     *
     * @param  array<string, mixed>|null  $previous
     */
    private function releaseClaim(Invoice $invoice, ?array $previous): void
    {
        $invoice->sdiMetadata()->update($previous ?? ['sdi_status' => 'draft', 'transmission_claimed_at' => null]);
    }

    /**
     * A file never transmitted keeps its name on a retry. After a rejection SDI wants a new name, or it answers 00002.
     *
     * @return array{file_name: string, progressive: string}
     */
    private function transmissionName(Invoice $invoice, ?InvoiceSdiMetadata $metadata): array
    {
        if ($metadata?->file_name !== null && $metadata->sdi_status !== 'rejected') {
            return ['file_name' => $metadata->file_name, 'progressive' => $this->fileNames->progressiveOf($metadata->file_name)];
        }

        return $this->fileNames->next($invoice->tenant);
    }

    private function recordFailure(Invoice $invoice, string $fileName, ?string $xml, string $format, SdiProviderException $e): void
    {
        $attributes = [
            'transmission_format' => $format,
            'sdi_status' => 'failed',
            'file_name' => $fileName,
            'transmission_claimed_at' => null,
        ];

        if ($xml !== null) {
            $attributes['xml_content'] = $xml;
        }

        $invoice->sdiMetadata()->updateOrCreate(['invoice_id' => $invoice->id], $attributes);

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
