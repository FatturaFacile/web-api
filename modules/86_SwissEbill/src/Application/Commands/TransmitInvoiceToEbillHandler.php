<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Commands;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\SwissEbill\Application\Services\EbillTransmissionLifecycle;
use Modules\SwissEbill\Domain\Enums\EbillEventSource;
use Modules\SwissEbill\Domain\Enums\EbillTransmissionStatus;
use Modules\SwissEbill\Domain\Exceptions\EbillException;
use Modules\SwissEbill\Domain\Repositories\EbillSettingRepositoryInterface;
use Modules\SwissEbill\Domain\Repositories\EbillTransmissionRepositoryInterface;
use Modules\SwissEbill\Infrastructure\Jobs\TransmitEbillJob;
use Modules\SwissEbill\Infrastructure\Xml\EbillPayloadBuilderService;
use Modules\SwissEbill\Models\EbillTransmission;

final class TransmitInvoiceToEbillHandler
{
    public function __construct(
        private readonly EbillSettingRepositoryInterface $settings,
        private readonly EbillTransmissionRepositoryInterface $transmissions,
        private readonly EbillPayloadBuilderService $builder,
        private readonly EbillTransmissionLifecycle $lifecycle,
    ) {}

    /**
     * @throws EbillException already_transmitted (409), ebill_disabled, or the EbillValidationException listing every missing datum
     */
    public function handle(TransmitInvoiceToEbillCommand $command): EbillTransmission
    {
        $setting = $this->settings->current();

        if ($setting === null || ! $setting->isReadyToTransmit()) {
            throw new EbillException('ebill_disabled', 'Swiss eBill is not enabled for this company.', 422);
        }

        $invoice = Invoice::query()->with(['tenant', 'contact.addresses', 'items'])->findOrFail($command->invoiceId);
        $active = $this->transmissions->activeForInvoice($invoice->id);

        if ($active !== null) {
            throw new EbillException('already_transmitted', 'This invoice already has an active eBill transmission.', 409);
        }

        if ($command->supersedesId !== null) {
            $previous = $this->transmissions->findOrFail($command->supersedesId);

            if ($previous->invoice_id !== $invoice->id || ! in_array($previous->status, [EbillTransmissionStatus::RejectedByDebtor, EbillTransmissionStatus::FailedTechnical, EbillTransmissionStatus::Cancelled], true)) {
                throw new EbillException('cannot_revise', 'Only a rejected, failed or cancelled transmission can be revised.', 409);
            }
        }

        $key = 'inv-'.$invoice->id.'-'.Str::lower(Str::random(12));
        // The payload is built (and validated) before anything is stored: an incomplete invoice leaves no transmission behind.
        $payload = $this->builder->build($invoice, $setting, $key);

        $transmission = DB::transaction(function () use ($command, $invoice, $payload, $key): EbillTransmission {
            $created = EbillTransmission::query()->create([
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'contact_id' => $invoice->contact_id,
                'supersedes_id' => $command->supersedesId,
                'status' => EbillTransmissionStatus::Draft->value,
                'channel_type' => $payload->channelType->value,
                'idempotency_key' => $key,
                'biller_id' => $payload->billerId,
                'recipient_id' => $payload->recipientId,
                'invoice_amount' => $invoice->total_gross,
                'currency' => $invoice->currency,
                'ebinterface_version' => $payload->version,
                'xml_payload' => $payload->xml,
                'xml_sha256' => $payload->xmlSha256,
                'pdf_sha256' => $payload->pdfSha256,
                'pdf_size_bytes' => $payload->pdfSizeBytes,
                'created_by' => $command->userId,
            ]);

            $created->appendEvent('prepared', EbillEventSource::System, null, EbillTransmissionStatus::Draft, ['xml_sha256' => $payload->xmlSha256, 'pdf_sha256' => $payload->pdfSha256], null, null, $command->userId);

            return $this->lifecycle->transition(
                $created,
                EbillTransmissionStatus::Queued,
                $command->source === 'user' ? EbillEventSource::User : EbillEventSource::System,
                [],
                $command->supersedesId !== null ? ['supersedes_id' => $command->supersedesId] : [],
                null,
                null,
                $command->userId,
            );
        });

        TransmitEbillJob::dispatch($transmission->id, (string) $transmission->tenant_id)->afterCommit();

        return $transmission;
    }
}
