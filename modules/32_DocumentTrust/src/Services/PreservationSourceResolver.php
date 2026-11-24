<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Services;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceSdiMetadata;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Documents\Models\DmsDocument;
use Modules\DocumentTrust\Enums\PreservationDocumentClass;
use Modules\DocumentTrust\Exceptions\DocumentTrustException;
use Modules\DocumentTrust\Support\PreservationSource;

/**
 * Finds the file and the metadata of a document to preserve, within the active tenant.
 *
 * Issued and received e-invoices come from the stored FatturaPA XML. Ledger books, payslips and contracts are generated
 * or signed elsewhere (CSV or PDF), so they are preserved from the file the document library holds: file them there
 * first, then add the library document to a package of its class. A source whose file is missing is refused.
 */
final readonly class PreservationSourceResolver
{
    /**
     * Inject the tenant context.
     */
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @param  array{document_date?: ?string, document_number?: ?string, counterpart_name?: ?string, counterpart_vat_number?: ?string}  $overrides  used for library documents, whose metadata the company states
     *
     * @throws DocumentTrustException when the document has no file to preserve
     */
    public function resolve(PreservationDocumentClass $class, string $sourceId, array $overrides = []): PreservationSource
    {
        return match ($class) {
            PreservationDocumentClass::IssuedInvoice => $this->issuedInvoice($sourceId),
            PreservationDocumentClass::ReceivedInvoice => $this->receivedInvoice($sourceId),
            default => $this->libraryDocument($class, $sourceId, $overrides),
        };
    }

    private function issuedInvoice(string $id): PreservationSource
    {
        $invoice = Invoice::with('contact')->findOrFail($id);
        $sdi = InvoiceSdiMetadata::where('invoice_id', $invoice->id)->first();

        if ($sdi === null || (string) $sdi->xml_content === '') {
            throw new DocumentTrustException('The invoice has no stored XML file.');
        }

        return new PreservationSource(
            'invoice', (string) $invoice->id, $this->xmlName($sdi->file_name, 'invoice-'.$invoice->invoice_number), 'application/xml', (string) $sdi->xml_content,
            'invoice', Carbon::parse((string) $invoice->date)->toDateString(), (string) $invoice->invoice_number,
            $this->contactName($invoice->contact), $invoice->contact?->vat_number, $this->ownVatNumber(),
            ['sdi_identifier' => $sdi->sdi_identifier, 'sdi_status' => $sdi->sdi_status, 'transmission_format' => $sdi->transmission_format],
        );
    }

    private function receivedInvoice(string $id): PreservationSource
    {
        $invoice = PassiveInvoice::with('supplier')->findOrFail($id);

        if ((string) $invoice->xml_payload === '') {
            throw new DocumentTrustException('The received invoice has no stored XML file.');
        }

        return new PreservationSource(
            'passive_invoice', (string) $invoice->id, $this->xmlName($invoice->sdi_file_name, 'received-invoice-'.$invoice->invoice_number), 'application/xml', (string) $invoice->xml_payload,
            'received_invoice', Carbon::parse((string) $invoice->date)->toDateString(), (string) $invoice->invoice_number,
            $this->contactName($invoice->supplier), $invoice->supplier?->vat_number, $this->ownVatNumber(),
            ['sdi_lot_id' => $invoice->sdi_lot_id],
        );
    }

    /**
     * @param  array{document_date?: ?string, document_number?: ?string, counterpart_name?: ?string, counterpart_vat_number?: ?string}  $overrides
     */
    private function libraryDocument(PreservationDocumentClass $class, string $id, array $overrides): PreservationSource
    {
        $document = DmsDocument::findOrFail($id);
        $version = $document->versions()->first();
        $content = $version === null ? null : Storage::disk((string) $version->disk)->get((string) $version->path);

        if ($version === null || $content === null) {
            throw new DocumentTrustException('The document has no stored file.');
        }

        if (! hash_equals((string) $version->sha256, hash('sha256', $content))) {
            throw new DocumentTrustException('The stored file of the document no longer matches its recorded hash, so it cannot be preserved.');
        }

        $metadata = $document->metadata ?? [];

        return new PreservationSource(
            'dms_document', (string) $document->id, (string) $version->original_name, $version->mime_type, $content,
            $class->value,
            $overrides['document_date'] ?? $metadata['document_date'] ?? null,
            $overrides['document_number'] ?? $metadata['document_number'] ?? null,
            $overrides['counterpart_name'] ?? $metadata['counterpart_name'] ?? null,
            $overrides['counterpart_vat_number'] ?? $metadata['counterpart_vat_number'] ?? null,
            $this->ownVatNumber(),
            ['title' => $document->title, 'dms_version_number' => $version->version_number],
        );
    }

    private function ownVatNumber(): ?string
    {
        return Tenant::find($this->tenantContext->getTenantId())?->vat_number;
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
}
