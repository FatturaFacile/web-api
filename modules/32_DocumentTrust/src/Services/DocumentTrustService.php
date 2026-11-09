<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Services;

use App\Models\Invoice;
use App\Models\InvoiceSdiMetadata;
use App\Models\PassiveInvoice;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
     * Check a stored signature of the active tenant against the XML the document has now.
     *
     * @return array{signature_id: string, document_type: string, document_id: string, driver: string, format: string, valid: bool, reason: ?string, content_changed: bool, trust_chain_validated: bool}
     */
    public function verify(string $signatureId): array
    {
        $signature = DocumentSignature::findOrFail($signatureId);

        try {
            $content = $this->content($signature->document_type, $signature->document_id);
        } catch (DocumentTrustException|ModelNotFoundException) {
            return $this->verification($signature, false, 'document_missing', true);
        }

        $changed = ! hash_equals((string) $signature->content_sha256, hash('sha256', $content));
        $result = $this->signatureDriver($signature->driver)->verify($content, $signature);

        return $this->verification($signature, $result['valid'] && ! $changed, $changed ? 'content_changed' : $result['reason'], $changed);
    }

    /**
     * @return array{signature_id: string, document_type: string, document_id: string, driver: string, format: string, valid: bool, reason: ?string, content_changed: bool, trust_chain_validated: bool}
     */
    private function verification(DocumentSignature $signature, bool $valid, ?string $reason, bool $changed): array
    {
        return [
            'signature_id' => (string) $signature->id,
            'document_type' => (string) $signature->document_type,
            'document_id' => (string) $signature->document_id,
            'driver' => (string) $signature->driver,
            'format' => (string) $signature->format,
            'valid' => $valid,
            'reason' => $reason,
            'content_changed' => $changed,
            'trust_chain_validated' => false,
        ];
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
