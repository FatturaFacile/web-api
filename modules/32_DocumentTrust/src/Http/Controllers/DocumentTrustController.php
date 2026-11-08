<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\DocumentTrust\Exceptions\DocumentTrustException;
use Modules\DocumentTrust\Models\DocumentSignature;
use Modules\DocumentTrust\Models\DocumentSigningSetting;
use Modules\DocumentTrust\Models\PreservationRecord;
use Modules\DocumentTrust\Services\DocumentTrustService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs and preserves the stored XML of invoices and configures the signing driver of the tenant.
 */
final class DocumentTrustController extends Controller
{
    use ApiResponse;

    /**
     * Inject the trust service.
     */
    public function __construct(private readonly DocumentTrustService $trust) {}

    #[OA\Post(path: '/api/v1/document-trust/signatures', operationId: 'signDocument', description: 'Signs the stored XML of a document with the driver of the tenant. `sha256-integrity` is only a fingerprint with no legal value; `cades-bes-p7m` uses the certificate of the tenant.', summary: 'Sign a document', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['document_type', 'document_id'], properties: [new OA\Property(property: 'document_type', type: 'string', enum: ['invoice', 'passive_invoice']), new OA\Property(property: 'document_id', type: 'string', format: 'uuid')])), tags: ['Document Trust'], responses: [
        new OA\Response(response: 201, description: 'Signature stored', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Document not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'No XML, or no certificate', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    /**
     * Sign the stored XML of a tenant document.
     */
    public function sign(Request $request): JsonResponse
    {
        $data = $this->documentReference($request);

        return $this->guarded(fn (): DocumentSignature => $this->trust->sign($data['document_type'], $data['document_id']));
    }

    #[OA\Get(path: '/api/v1/document-trust/signatures', operationId: 'listDocumentSignatures', description: 'Signatures of a document.', summary: 'List signatures', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Document Trust'], parameters: [new OA\Parameter(name: 'document_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'The signatures', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
    ])]
    /**
     * List the signatures of a document.
     */
    public function signatures(Request $request): JsonResponse
    {
        $validated = $request->validate(['document_id' => ['required', 'uuid']]);

        return $this->successResponse(DocumentSignature::where('document_id', $validated['document_id'])->latest()->get(), 'Signatures retrieved');
    }

    #[OA\Post(path: '/api/v1/document-trust/preservation', operationId: 'preserveDocument', description: 'Hands a document to the preservation driver. The local driver records `fingerprint_only`, which is not legal preservation. The `accredited` driver refuses until a provider is contracted.', summary: 'Preserve a document', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['document_type', 'document_id'], properties: [new OA\Property(property: 'document_type', type: 'string', enum: ['invoice', 'passive_invoice']), new OA\Property(property: 'document_id', type: 'string', format: 'uuid')])), tags: ['Document Trust'], responses: [
        new OA\Response(response: 201, description: 'Record stored', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Document not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'No XML, or driver unavailable', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    /**
     * Hand a document to the preservation driver.
     */
    public function preserve(Request $request): JsonResponse
    {
        $data = $this->documentReference($request);

        return $this->guarded(fn (): PreservationRecord => $this->trust->preserve($data['document_type'], $data['document_id']));
    }

    #[OA\Get(path: '/api/v1/document-trust/preservation', operationId: 'listPreservationRecords', description: 'Preservation records of a document.', summary: 'List preservation records', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Document Trust'], parameters: [new OA\Parameter(name: 'document_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'The records', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
    ])]
    /**
     * List the preservation records of a document.
     */
    public function preservationRecords(Request $request): JsonResponse
    {
        $validated = $request->validate(['document_id' => ['required', 'uuid']]);

        return $this->successResponse(PreservationRecord::where('document_id', $validated['document_id'])->latest()->get(), 'Preservation records retrieved');
    }

    #[OA\Get(path: '/api/v1/document-trust/settings', operationId: 'getDocumentSigningSettings', description: 'For owners and admins. The driver and the certificate expiry; certificate, key and passphrase are never returned.', summary: 'Show document signing settings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Document Trust'], responses: [
        new OA\Response(response: 200, description: 'The settings', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
    ])]
    /**
     * Show the signing settings without any secret.
     */
    public function settings(TenantContext $tenantContext): JsonResponse
    {
        $settings = DocumentSigningSetting::firstOrNew(['tenant_id' => $tenantContext->getTenantId()], ['driver' => 'local']);

        return $this->successResponse($settings, 'Signing settings retrieved');
    }

    #[OA\Put(path: '/api/v1/document-trust/settings', operationId: 'updateDocumentSigningSettings', description: 'For owners and admins. Chooses the signature driver and stores the PEM certificate and key encrypted. The pair must match and the certificate must be valid today.', summary: 'Configure document signing', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['driver'], properties: [
        new OA\Property(property: 'driver', type: 'string', enum: ['local', 'cades']),
        new OA\Property(property: 'certificate', type: 'string', nullable: true),
        new OA\Property(property: 'private_key', type: 'string', nullable: true),
        new OA\Property(property: 'passphrase', type: 'string', nullable: true),
    ])), tags: ['Document Trust'], responses: [
        new OA\Response(response: 200, description: 'Settings saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 422, description: 'Invalid certificate', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    /**
     * Choose the signing driver and store the validated certificate pair (encrypted).
     */
    public function updateSettings(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validate([
            'driver' => ['required', 'in:local,cades'],
            'certificate' => ['required_if:driver,cades', 'nullable', 'string', 'max:20000'],
            'private_key' => ['required_if:driver,cades', 'nullable', 'string', 'max:20000'],
            'passphrase' => ['nullable', 'string', 'max:255'],
        ]);

        $settings = DocumentSigningSetting::firstOrNew(['tenant_id' => $tenantContext->getTenantId()]);
        $settings->fill($data);

        $settings->certificate_expires_at = $data['driver'] === 'cades'
            ? $this->validatedExpiry($data['certificate'], $data['private_key'], $data['passphrase'] ?? '')
            : null;

        $settings->save();

        return $this->successResponse($settings, 'Signing settings saved');
    }

    /**
     * Check the certificate and key match and are valid today; return the expiry.
     */
    private function validatedExpiry(string $certificate, string $privateKey, string $passphrase): Carbon
    {
        $parsed = @openssl_x509_parse($certificate);
        $key = @openssl_pkey_get_private($privateKey, $passphrase);

        if ($parsed === false || $key === false || ! @openssl_x509_check_private_key($certificate, $key)) {
            throw ValidationException::withMessages(['certificate' => __('sales.document_signing_invalid_certificate')]);
        }

        $expiresAt = Carbon::createFromTimestamp($parsed['validTo_time_t']);

        if ($expiresAt->isPast() || Carbon::createFromTimestamp($parsed['validFrom_time_t'])->isFuture()) {
            throw ValidationException::withMessages(['certificate' => __('sales.document_signing_invalid_certificate')]);
        }

        return $expiresAt;
    }

    /**
     * @return array{document_type: string, document_id: string}
     */
    private function documentReference(Request $request): array
    {
        return $request->validate(['document_type' => ['required', 'in:invoice,passive_invoice'], 'document_id' => ['required', 'uuid']]);
    }

    /**
     * @param  Closure(): mixed  $operation
     */
    private function guarded(Closure $operation): JsonResponse
    {
        try {
            return $this->successResponse($operation(), 'Operation successful', Response::HTTP_CREATED);
        } catch (DocumentTrustException $e) {
            throw ValidationException::withMessages(['document' => $e->getMessage()]);
        }
    }
}
