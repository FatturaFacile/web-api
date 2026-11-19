<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Services;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceSdiMetadata;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Modules\DigitalPreservation\Application\DTOs\PreservationCandidate;
use Modules\DigitalPreservation\Domain\Enums\DocumentCategory;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatchItem;
use Modules\Documents\Models\DmsDocument;
use Modules\SDI\Models\FpaDocument;
use Modules\SDI\Models\SdiNotification;

/**
 * Finds the documents of the active tenant that are not preserved yet, per category and period:
 * issued and received e-invoices (stored FatturaPA XML), SDI notifications (stored XML) and library documents the
 * company filed for ledgers, tax documents or dematerialized analog originals (tagged with `preservation_category` in the
 * document metadata).
 */
final readonly class PreservationCandidateCollector
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @return Collection<int, PreservationCandidate>
     */
    public function collect(DocumentCategory $category, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $candidates = match ($category) {
            DocumentCategory::ActiveInvoices => $this->activeInvoices($from, $to),
            DocumentCategory::PassiveInvoices => $this->passiveInvoices($from, $to),
            DocumentCategory::SdiNotifications => $this->notifications($from, $to),
            default => $this->libraryDocuments($category, $from, $to),
        };

        $type = $candidates->first()?->preservableType;

        if ($type === null) {
            return $candidates;
        }

        $done = PreservationBatchItem::where('preservable_type', $type)->whereIn('preservable_id', $candidates->pluck('preservableId')->all())->pluck('preservable_id')->all();

        return $candidates->reject(static fn (PreservationCandidate $c): bool => in_array($c->preservableId, $done, true))->values();
    }

    /**
     * Number of documents still to preserve, per category, across all periods.
     */
    public function pendingCount(): int
    {
        $from = Carbon::create(2000, 1, 1);
        $to = Carbon::now()->endOfDay();
        $total = 0;

        foreach (DocumentCategory::cases() as $category) {
            $total += $this->collect($category, $from, $to)->count();
        }

        return $total;
    }

    /**
     * @return Collection<int, PreservationCandidate>
     */
    private function activeInvoices(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $out = collect();

        foreach (Invoice::with('contact')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date')->orderBy('invoice_number')->get() as $invoice) {
            $sdi = InvoiceSdiMetadata::where('invoice_id', $invoice->id)->first();

            if ($sdi === null || (string) $sdi->xml_content === '') {
                continue;
            }

            $name = $this->contactName($invoice->contact);
            $date = Carbon::parse((string) $invoice->date)->toDateString();
            $signed = str_ends_with(strtolower((string) $sdi->file_name), '.p7m');
            $file = $this->xmlName($sdi->file_name, 'invoice-'.$invoice->invoice_number);

            $out->push(new PreservationCandidate(
                'invoice', (string) $invoice->id, $file, $signed ? 'application/pkcs7-mime' : 'application/xml', (string) $sdi->xml_content,
                DocumentCategory::ActiveInvoices->agidType(), (string) $invoice->invoice_number, $date, $name, $invoice->contact?->vat_number,
                [
                    'agid_type' => DocumentCategory::ActiveInvoices->agidType(), 'id_doc' => $this->invoiceDocId((string) $invoice->invoice_number, $date),
                    'subject' => "Fattura elettronica n. {$invoice->invoice_number} del {$date}", 'standard' => 'FatturaPA-v1.2.2',
                    'sender' => ['name' => $this->ownerName(), 'tax_id' => $this->ownerVat()], 'recipient' => ['name' => $name, 'tax_id' => $invoice->contact?->vat_number],
                    'fiscal' => ['sdi_identifier' => $sdi->sdi_identifier, 'total_amount' => number_format((float) $invoice->total_gross, 2, '.', ''), 'currency' => $invoice->currency ?: 'EUR'],
                    'signature' => ['signed' => $signed, 'format' => $signed ? 'CAdES-BES' : 'NONE', 'outcome' => $signed ? 'NOT_VERIFIED' : 'NOT_APPLICABLE'],
                    'formation_date' => Carbon::parse((string) $invoice->date)->startOfDay()->toIso8601String(),
                ],
            ));
        }

        return $out;
    }

    /**
     * @return Collection<int, PreservationCandidate>
     */
    private function passiveInvoices(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $out = collect();

        foreach (PassiveInvoice::with('supplier')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date')->orderBy('invoice_number')->get() as $invoice) {
            if ((string) $invoice->xml_payload === '') {
                continue;
            }

            $name = $this->contactName($invoice->supplier);
            $date = Carbon::parse((string) $invoice->date)->toDateString();

            $out->push(new PreservationCandidate(
                'passive_invoice', (string) $invoice->id, $this->xmlName($invoice->sdi_file_name, 'received-invoice-'.$invoice->invoice_number), 'application/xml', (string) $invoice->xml_payload,
                DocumentCategory::PassiveInvoices->agidType(), (string) $invoice->invoice_number, $date, $name, $invoice->supplier?->vat_number,
                [
                    'agid_type' => DocumentCategory::PassiveInvoices->agidType(), 'id_doc' => $this->invoiceDocId((string) $invoice->invoice_number, $date, 'PAS'),
                    'subject' => "Fattura passiva n. {$invoice->invoice_number} del {$date}", 'standard' => 'FatturaPA-v1.2.2',
                    'sender' => ['name' => $name, 'tax_id' => $invoice->supplier?->vat_number], 'recipient' => ['name' => $this->ownerName(), 'tax_id' => $this->ownerVat()],
                    'fiscal' => ['sdi_identifier' => null, 'total_amount' => number_format((float) $invoice->total_amount, 2, '.', ''), 'currency' => $invoice->currency ?: 'EUR'],
                    'signature' => ['signed' => false, 'format' => 'NONE', 'outcome' => 'NOT_APPLICABLE'],
                    'formation_date' => Carbon::parse((string) $invoice->date)->startOfDay()->toIso8601String(),
                ],
            ));
        }

        return $out;
    }

    /**
     * @return Collection<int, PreservationCandidate>
     */
    private function notifications(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $out = collect();
        $notifications = SdiNotification::with('document')->whereBetween('received_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('received_at')->get();

        foreach ($notifications as $notification) {
            $xml = $notification->raw_xml_path === null ? null : Storage::disk('local')->get($notification->raw_xml_path);

            if (! is_string($xml) || $xml === '') {
                continue;
            }

            /** @var FpaDocument|null $document */
            $document = $notification->document;
            $type = $notification->notification_type->value;
            $sdiId = (string) ($notification->sdi_identifier ?? $notification->id);
            $related = [];

            if ($document !== null && $document->document_number !== null && $document->document_date !== null) {
                $related[] = ['id_doc' => $this->invoiceDocId((string) $document->document_number, Carbon::parse((string) $document->document_date)->toDateString(), $document->passive_invoice_id !== null ? 'PAS' : 'INV'), 'relation' => 'RICEVUTA_DI'];
            }

            $out->push(new PreservationCandidate(
                'sdi_notification', (string) $notification->id, basename((string) $notification->raw_xml_path), 'application/xml', $xml,
                'NOTIFICA_SDI_'.$type, $document?->document_number, $notification->received_at?->toDateString(), null, null,
                [
                    'agid_type' => 'NOTIFICA_SDI_'.$type, 'id_doc' => 'DOC-SDI-'.$type.'-'.preg_replace('/[^A-Za-z0-9]/', '', $sdiId), 'standard' => 'SDI-Messaggi-v1.1',
                    'subject' => "Notifica SDI {$type} (IdSDI {$sdiId})", 'related' => $related,
                    'formation_date' => ($notification->received_at ?? now())->toIso8601String(),
                ],
            ));
        }

        return $out;
    }

    /**
     * @return Collection<int, PreservationCandidate>
     */
    private function libraryDocuments(DocumentCategory $category, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $out = collect();
        $documents = DmsDocument::where('metadata->preservation_category', $category->value)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('created_at')->get();

        foreach ($documents as $document) {
            $version = $document->versions()->first();
            $content = $version === null ? null : Storage::disk((string) $version->disk)->get((string) $version->path);

            if ($version === null || ! is_string($content) || ! hash_equals((string) $version->sha256, hash('sha256', $content))) {
                continue;
            }

            $meta = $document->metadata ?? [];
            $number = $meta['document_number'] ?? null;
            $date = $meta['document_date'] ?? $document->created_at?->toDateString();

            $out->push(new PreservationCandidate(
                'dms_document', (string) $document->id, (string) $version->original_name, (string) $version->mime_type, $content,
                $category->agidType(), $number, $date, $meta['counterpart_name'] ?? null, $meta['counterpart_vat_number'] ?? null,
                [
                    'agid_type' => $category->agidType(), 'id_doc' => 'DOC-'.strtoupper(substr($category->value, 0, 3)).'-'.substr((string) $document->id, 0, 8),
                    'digital_native' => $category->isDigitalNative(), 'subject' => (string) ($document->title ?? $version->original_name),
                    'formation_date' => ($document->created_at ?? now())->toIso8601String(),
                ],
            ));
        }

        return $out;
    }

    private function invoiceDocId(string $number, string $date, string $kind = 'INV'): string
    {
        return 'DOC-'.$kind.'-'.substr($date, 0, 4).'-'.preg_replace('/[^A-Za-z0-9]/', '', $number);
    }

    private function contactName(?Contact $contact): ?string
    {
        if ($contact === null) {
            return null;
        }

        return $contact->company_name ?: trim($contact->first_name.' '.$contact->last_name);
    }

    private function xmlName(?string $stored, string $fallback): string
    {
        return $stored !== null && $stored !== '' ? $stored : preg_replace('/[^A-Za-z0-9._-]+/', '_', $fallback).'.xml';
    }

    private function ownerName(): ?string
    {
        return Tenant::find($this->tenantContext->getTenantId())?->legal_name;
    }

    private function ownerVat(): ?string
    {
        return Tenant::find($this->tenantContext->getTenantId())?->vat_number;
    }
}
