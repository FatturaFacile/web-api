<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Services;

use App\Models\Invoice;
use App\Models\InvoiceSdiMetadata;
use App\Models\PassiveInvoice;
use App\Services\Tenancy\TenantContext;
use Modules\DocumentTrust\Contracts\PreservationDriver;
use Modules\DocumentTrust\Contracts\SignatureDriver;
use Modules\DocumentTrust\Drivers\AccreditedPreservationDriver;
use Modules\DocumentTrust\Drivers\CadesCertificateDriver;
use Modules\DocumentTrust\Drivers\LocalIntegrityDriver;
use Modules\DocumentTrust\Drivers\LocalPreservationDriver;
use Modules\DocumentTrust\Exceptions\DocumentTrustException;
use Modules\DocumentTrust\Models\DocumentSignature;
use Modules\DocumentTrust\Models\DocumentSigningSetting;
use Modules\DocumentTrust\Models\PreservationRecord;

/**
 * Signs and preserves the stored FatturaPA file of a document, through the driver of the tenant.
 */
final readonly class DocumentTrustService
{
    /**
     * Inject the tenant context.
     */
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Sign the XML of a tenant document with the driver of the tenant and store the result.
     */
    public function sign(string $documentType, string $documentId): DocumentSignature
    {
        $content = $this->content($documentType, $documentId);
        $settings = DocumentSigningSetting::where('tenant_id', $this->tenantContext->getTenantId())->first();
        $driver = $this->signatureDriver($settings?->driver ?? 'local');
        $signature = $driver->sign($content, $settings);

        return DocumentSignature::create([
            ...$signature,
            'document_type' => $documentType,
            'document_id' => $documentId,
            'driver' => $driver->name(),
            'content_sha256' => hash('sha256', $content),
        ]);
    }

    /**
     * Preserve the XML of a tenant document through the configured driver and store the record.
     */
    public function preserve(string $documentType, string $documentId): PreservationRecord
    {
        $content = $this->content($documentType, $documentId);
        $driver = $this->preservationDriver();

        return PreservationRecord::create([
            ...$driver->preserve("{$documentType}-{$documentId}.xml", $content),
            'document_type' => $documentType,
            'document_id' => $documentId,
            'provider' => $driver->name(),
            'content_sha256' => hash('sha256', $content),
        ]);
    }

    /**
     * The stored XML of the document, resolved within the active tenant.
     */
    private function content(string $documentType, string $documentId): string
    {
        $content = $documentType === 'passive_invoice'
            ? PassiveInvoice::findOrFail($documentId)->xml_payload
            : InvoiceSdiMetadata::where('invoice_id', Invoice::findOrFail($documentId)->id)->value('xml_content');

        return $content ?? throw new DocumentTrustException('The document has no stored XML file.');
    }

    /**
     * Resolve the signature driver by name.
     */
    private function signatureDriver(string $driver): SignatureDriver
    {
        return app($driver === 'cades' ? CadesCertificateDriver::class : LocalIntegrityDriver::class);
    }

    /**
     * Resolve the preservation driver from the configuration.
     */
    private function preservationDriver(): PreservationDriver
    {
        return app(config('services.preservation.driver') === 'accredited' ? AccreditedPreservationDriver::class : LocalPreservationDriver::class);
    }
}
