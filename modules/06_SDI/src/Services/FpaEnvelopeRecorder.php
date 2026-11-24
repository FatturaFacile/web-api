<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use App\Models\Invoice;
use App\Models\PassiveInvoice;
use Illuminate\Support\Facades\Storage;
use Modules\SDI\Enums\FpaDirection;
use Modules\SDI\Enums\FpaStatus;
use Modules\SDI\Enums\SdiNotificationType;
use Modules\SDI\Enums\TipoDocumento;
use Modules\SDI\Models\FpaDocument;

/**
 * Keeps the electronic invoice envelope (`fpa_documents`) in step with the existing send flow, so the cockpit and the
 * SDI timeline show every transmission and every receipt without a second code path.
 */
final class FpaEnvelopeRecorder
{
    /** @var array<string, SdiNotificationType> receipt codes of the existing flow */
    private const array EventTypes = [
        'RC' => SdiNotificationType::RC,
        'NS' => SdiNotificationType::NS,
        'MC' => SdiNotificationType::MC,
        'AT' => SdiNotificationType::AT,
        'DT' => SdiNotificationType::DT,
        'NEC1' => SdiNotificationType::NE,
        'NEC2' => SdiNotificationType::NE,
    ];

    public function __construct(private readonly SdiNotificationProcessorService $processor) {}

    public function recordSent(Invoice $invoice, string $fileName, string $xml, string $format, string $sdiIdentifier): FpaDocument
    {
        return $this->upsert($invoice, $fileName, $xml, $format, FpaStatus::Transmitted, ['sdi_identifier' => $sdiIdentifier, 'transmitted_at' => now(), 'validation_errors' => null]);
    }

    public function recordFailure(Invoice $invoice, string $fileName, ?string $xml, string $format, string $error): FpaDocument
    {
        return $this->upsert($invoice, $fileName, $xml, $format, FpaStatus::ValidationFailed, [
            'validation_errors' => [['code' => 'SEND_FAILED', 'description' => mb_substr($error, 0, 500)]],
        ]);
    }

    /**
     * A supplier invoice read from SDI or uploaded in the inbox: the envelope keeps the original file and links the
     * received-invoice draft created from it.
     */
    public function recordInbound(PassiveInvoice $invoice, ?string $fileName, string $xml): FpaDocument
    {
        $name = $fileName ?: 'inbound_'.substr(hash('sha256', $xml), 0, 12).'.xml';
        $path = sprintf('sdi/%s/inbound/%s', $invoice->tenant_id, $name);
        Storage::disk('local')->put($path, $xml);
        $format = preg_match('/versione="(FPR12|FPA12|FSM10)"/', $xml, $match) === 1 ? $match[1] : 'FPR12';

        $document = FpaDocument::withoutGlobalScopes()->firstOrNew(['tenant_id' => $invoice->tenant_id, 'passive_invoice_id' => $invoice->id]);
        $document->forceFill([
            'direction' => FpaDirection::Inbound,
            'format_transmission' => $format,
            'document_type' => (string) ($invoice->document_type ?: 'TD01'),
            'document_number' => (string) $invoice->invoice_number,
            'document_date' => $invoice->date,
            'file_name' => $name,
            'status' => FpaStatus::InboundProcessed,
            'xml_storage_path' => $path,
            'sha256_hash' => hash('sha256', $xml),
        ])->save();

        return $document;
    }

    /**
     * Applies a receipt of the existing flow (RC, NS, MC, AT, DT, NEC1, NEC2) to the envelope of the invoice.
     */
    public function mirrorEvent(Invoice $invoice, string $eventType, ?string $message): void
    {
        $type = self::EventTypes[$eventType] ?? null;
        $document = FpaDocument::withoutGlobalScopes()->where('invoice_id', $invoice->id)->latest()->first();

        if ($type === null || $document === null) {
            return;
        }

        $id = htmlspecialchars((string) $document->sdi_identifier, ENT_XML1);
        $note = htmlspecialchars((string) $message, ENT_XML1);
        $messageId = substr(hash('sha256', $document->id.$eventType.$message), 0, 16);
        $body = match ($type) {
            SdiNotificationType::NS => '<ListaErrori><Errore><Codice>NS</Codice><Descrizione>'.$note.'</Descrizione></Errore></ListaErrori>',
            SdiNotificationType::NE => '<Esito>'.($eventType === 'NEC2' ? 'EC02' : 'EC01').'</Esito>',
            default => '',
        };

        $this->processor->process(
            $document,
            $type,
            sprintf('<Notifica><IdentificativoSdI>%s</IdentificativoSdI><MessageId>%s</MessageId>%s</Notifica>', $id, $messageId, $body),
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function upsert(Invoice $invoice, string $fileName, ?string $xml, string $format, FpaStatus $status, array $extra): FpaDocument
    {
        $document = FpaDocument::withoutGlobalScopes()->firstOrNew(['tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id, 'file_name' => $fileName]);
        $contact = $invoice->contact;
        $attributes = [
            'direction' => FpaDirection::Outbound,
            'format_transmission' => $format,
            'document_type' => TipoDocumento::ofInvoice($invoice)->value,
            'document_number' => (string) $invoice->invoice_number,
            'document_date' => $invoice->date,
            'transmission_progressive' => (new SdiFileNamer)->progressiveOf($fileName),
            'recipient_code' => $contact?->sdi_code ?: null,
            'recipient_pec' => $contact?->pec ?: null,
            'status' => $status,
            ...$extra,
        ];

        if ($xml !== null) {
            $path = sprintf('sdi/%s/%s/%s', $invoice->tenant_id, $invoice->id, $fileName);
            Storage::disk('local')->put($path, $xml);
            $attributes['xml_storage_path'] = $path;
            $attributes['sha256_hash'] = hash('sha256', $xml);
        }

        $document->forceFill($attributes)->save();

        return $document;
    }
}
