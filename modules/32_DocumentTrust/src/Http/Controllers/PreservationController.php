<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Rules\PublicHttpUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\DocumentTrust\Enums\PreservationDocumentClass;
use Modules\DocumentTrust\Exceptions\DocumentTrustException;
use Modules\DocumentTrust\Http\Resources\PreservationItemResource;
use Modules\DocumentTrust\Http\Resources\PreservationPackageResource;
use Modules\DocumentTrust\Http\Resources\PreservationVerificationResource;
use Modules\DocumentTrust\Models\PreservationAccessLog;
use Modules\DocumentTrust\Models\PreservationItem;
use Modules\DocumentTrust\Models\PreservationPackage;
use Modules\DocumentTrust\Models\PreservationSetting;
use Modules\DocumentTrust\Services\PreservationAccessLogger;
use Modules\DocumentTrust\Services\PreservationPackageService;
use Modules\DocumentTrust\Services\PreservationRetention;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Preservation packages: the technical basis of long-term document preservation. NOT a certified conservatore (AgID
 * accreditation); the manifest and index follow a simple documented structure, not UNI SINCRO. Every access is logged.
 */
final class PreservationController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(
        private readonly PreservationPackageService $packages,
        private readonly PreservationAccessLogger $logger,
        private readonly PreservationRetention $retention,
    ) {}

    #[OA\Get(path: '/api/v1/document-trust/preservation-packages', operationId: 'listPreservationPackages', description: 'Preservation packages of the active tenant, newest first, with the last integrity check of each. The access is logged.', summary: 'List preservation packages', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Document Trust'], parameters: [
        new OA\Parameter(name: 'document_class', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['issued_invoice', 'received_invoice', 'ledger_book', 'payslip', 'contract'])),
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'closed'])),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
    ], responses: [
        new OA\Response(response: 200, description: 'The packages', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    /**
     * List the packages.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'document_class' => ['nullable', Rule::in(PreservationDocumentClass::values())],
            'status' => ['nullable', Rule::in([PreservationPackage::Open, PreservationPackage::Closed])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = PreservationPackage::with(['latestVerification', 'disposal'])
            ->when($filters['document_class'] ?? null, fn ($query, string $class) => $query->where('document_class', $class))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->gridPaginate((int) ($filters['per_page'] ?? 25));

        $this->logger->record('list', null, array_filter($filters));

        return $this->successResponse(PreservationPackageResource::collection($page->getCollection()), 'Preservation packages retrieved', meta: ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]);
    }

    #[OA\Post(path: '/api/v1/document-trust/preservation-packages', operationId: 'createPreservationPackage', description: 'Opens an empty package for one document class. Files are added with the items endpoint and the package is then closed. Not a certified conservatore.', summary: 'Open a preservation package', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['document_class', 'name'], properties: [
        new OA\Property(property: 'document_class', type: 'string', enum: ['issued_invoice', 'received_invoice', 'ledger_book', 'payslip', 'contract']),
        new OA\Property(property: 'name', type: 'string', example: 'Fatture emesse 2026'),
        new OA\Property(property: 'period_from', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'period_to', type: 'string', format: 'date', nullable: true),
    ])), tags: ['Document Trust'], responses: [
        new OA\Response(response: 201, description: 'Package opened', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    /**
     * Open a package.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_class' => ['required', Rule::in(PreservationDocumentClass::values())],
            'name' => ['required', 'string', 'max:200'],
            'period_from' => ['nullable', 'date_format:Y-m-d'],
            'period_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_from'],
        ]);

        return $this->successResponse(new PreservationPackageResource($this->packages->create($data, $request->user())), 'Preservation package opened', Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/document-trust/preservation-packages/{id}', operationId: 'showPreservationPackage', description: 'A package with its files (hash and metadata) and its integrity history. The access is logged.', summary: 'Show a preservation package', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Document Trust'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'The package', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    /**
     * Show a package.
     */
    public function show(string $id): JsonResponse
    {
        $package = PreservationPackage::with(['latestVerification', 'disposal'])->findOrFail($id);
        $this->logger->record('view', $package);

        return $this->successResponse([
            'package' => new PreservationPackageResource($package),
            'items' => PreservationItemResource::collection($package->items()->get()),
            'verifications' => PreservationVerificationResource::collection($package->verifications()->limit(20)->get()),
        ], 'Preservation package retrieved');
    }

    #[OA\Delete(path: '/api/v1/document-trust/preservation-packages/{id}', operationId: 'deletePreservationPackage', description: 'Deletes an OPEN package and its files. A closed package is immutable and answers 409.', summary: 'Delete an open preservation package', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Document Trust'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 409, description: 'The package is closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    /**
     * Delete an open package.
     */
    public function destroy(string $id): JsonResponse
    {
        $package = PreservationPackage::findOrFail($id);
        Gate::authorize('delete', $package);

        foreach ($package->items()->get() as $item) {
            $this->packages->removeItem($package, $item);
        }

        $package->delete();
        $this->logger->record('delete', $package);

        return $this->successResponse(null, 'Preservation package deleted');
    }

    #[OA\Post(path: '/api/v1/document-trust/preservation-packages/{id}/items', operationId: 'addPreservationItem', description: 'Copies a document into an open package and records its SHA-256 and metadata. `source_id` is an invoice (class issued_invoice), a received invoice (received_invoice) or a document of the library (ledger_book, payslip, contract). For library documents the optional metadata fields state what the manifest records. Closed packages answer 409.', summary: 'Add a document to a package', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['source_id'], properties: [
        new OA\Property(property: 'source_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'document_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'document_number', type: 'string', nullable: true),
        new OA\Property(property: 'counterpart_name', type: 'string', nullable: true),
        new OA\Property(property: 'counterpart_vat_number', type: 'string', nullable: true),
    ])), tags: ['Document Trust'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 201, description: 'File added', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Package or source not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 409, description: 'The package is closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'No file, duplicate, or outside the period', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    /**
     * Add a document.
     */
    public function addItem(Request $request, string $id): JsonResponse
    {
        $package = PreservationPackage::findOrFail($id);
        Gate::authorize('update', $package);
        $data = $request->validate([
            'source_id' => ['required', 'uuid'],
            'document_date' => ['nullable', 'date_format:Y-m-d'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'counterpart_name' => ['nullable', 'string', 'max:255'],
            'counterpart_vat_number' => ['nullable', 'string', 'max:40'],
        ]);

        return $this->guarded(fn (): JsonResponse => $this->successResponse(
            new PreservationItemResource($this->packages->addItem($package, $data['source_id'], array_diff_key($data, ['source_id' => 1]))),
            'File added to the package',
            Response::HTTP_CREATED,
        ));
    }

    #[OA\Delete(path: '/api/v1/document-trust/preservation-packages/{id}/items/{itemId}', operationId: 'removePreservationItem', description: 'Removes a file from an OPEN package. Files of a closed package are immutable and answer 409.', summary: 'Remove a file from an open package', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Document Trust'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Removed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 409, description: 'The package is closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    /**
     * Remove a file from an open package.
     */
    public function removeItem(string $id, string $itemId): JsonResponse
    {
        $package = PreservationPackage::findOrFail($id);
        Gate::authorize('update', $package);
        $this->packages->removeItem($package, PreservationItem::where('package_id', $package->id)->findOrFail($itemId));

        return $this->successResponse(null, 'File removed from the package');
    }

    #[OA\Post(path: '/api/v1/document-trust/preservation-packages/{id}/close', operationId: 'closePreservationPackage', description: 'Closes the package: fixes the retention period, writes the manifest, computes the package hash (SHA-256 of the manifest) and asks the time-stamp provider to stamp it. The provider name and token are recorded; the default local provider is the server clock, issues no token and is NOT a qualified time stamp (`timestamp_qualified` false). After closing, nothing in the package can change. If the provider fails the package stays open.', summary: 'Close a preservation package', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Document Trust'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'The closed package', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 409, description: 'Already closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Empty package, or the provider failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    /**
     * Close a package.
     */
    public function close(Request $request, string $id): JsonResponse
    {
        $package = PreservationPackage::findOrFail($id);
        Gate::authorize('close', $package);

        return $this->guarded(fn (): JsonResponse => $this->successResponse(new PreservationPackageResource($this->packages->close($package, $request->user())), 'Preservation package closed'));
    }

    #[OA\Post(path: '/api/v1/document-trust/preservation-packages/{id}/verify', operationId: 'verifyPreservationPackage', description: 'Recomputes the SHA-256 of every file and of the manifest of a closed package and records the result in the verification history. Failure reasons: manifest_hash_mismatch, item_missing, item_record_altered, file_missing, hash_mismatch, item_not_in_manifest. A periodic job does the same for every closed package.', summary: 'Verify a preservation package', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Document Trust'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'The result (passed or failed)', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'The package is not closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    /**
     * Verify a package now.
     */
    public function verify(Request $request, string $id): JsonResponse
    {
        $package = PreservationPackage::findOrFail($id);

        return $this->guarded(fn (): JsonResponse => $this->successResponse(new PreservationVerificationResource($this->packages->verify($package, 'manual', (string) $request->user()->id)), 'Preservation package verified'));
    }

    #[OA\Get(path: '/api/v1/document-trust/preservation-packages/{id}/export', operationId: 'exportPreservationPackage', description: 'Zip of a closed package: manifest.json (the exact text that was hashed), index.csv, README.txt and the files under files/. The integrity of the package is checked first and a package that fails is not exported (409). The export is logged. Simple documented structure, NOT UNI SINCRO; not a certified conservatore.', summary: 'Export a preservation package', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Document Trust'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'The zip file', content: new OA\MediaType(mediaType: 'application/zip', schema: new OA\Schema(type: 'string', format: 'binary'))),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 409, description: 'The package failed its integrity check', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'The package is not closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    /**
     * Export a closed package as a zip.
     */
    public function export(string $id): BinaryFileResponse|JsonResponse
    {
        $package = PreservationPackage::findOrFail($id);

        try {
            $path = $this->packages->export($package);
        } catch (DocumentTrustException $exception) {
            if (str_contains($exception->getMessage(), 'integrity')) {
                $this->logger->record('export_refused', $package, ['reason' => 'integrity_check_failed']);

                return $this->problemDetailsResponse('Integrity check failed', $exception->getMessage(), Response::HTTP_CONFLICT);
            }

            throw ValidationException::withMessages(['package' => $exception->getMessage()]);
        }

        $this->logger->record('export', $package, ['package_hash' => $package->package_hash]);

        return response()->download($path, "preservation-{$package->id}.zip", ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    #[OA\Get(path: '/api/v1/document-trust/preservation-items', operationId: 'searchPreservationItems', description: 'Searches the preserved files of the active tenant by metadata. Text filters are case-insensitive substrings (number, counterpart) or exact (vat_number). The search is logged.', summary: 'Search preserved documents', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Document Trust'], parameters: [
        new OA\Parameter(name: 'document_class', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['issued_invoice', 'received_invoice', 'ledger_book', 'payslip', 'contract'])),
        new OA\Parameter(name: 'package_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'number', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'counterpart', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'vat_number', in: 'query', required: false, description: 'VAT number of the counterpart or of the company', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'sha256', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
    ], responses: [
        new OA\Response(response: 200, description: 'The matching files', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    /**
     * Search preserved documents by metadata.
     */
    public function search(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'document_class' => ['nullable', Rule::in(PreservationDocumentClass::values())],
            'package_id' => ['nullable', 'uuid'],
            'number' => ['nullable', 'string', 'max:100'],
            'counterpart' => ['nullable', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:40'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'sha256' => ['nullable', 'string', 'size:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = PreservationItem::with('package:id,status')
            ->when($filters['document_class'] ?? null, fn ($query, string $value) => $query->where('document_class', $value))
            ->when($filters['package_id'] ?? null, fn ($query, string $value) => $query->where('package_id', $value))
            ->when($filters['number'] ?? null, fn ($query, string $value) => $query->whereRaw('lower(document_number) like ?', ['%'.mb_strtolower(addcslashes($value, '%_\\')).'%']))
            ->when($filters['counterpart'] ?? null, fn ($query, string $value) => $query->whereRaw('lower(counterpart_name) like ?', ['%'.mb_strtolower(addcslashes($value, '%_\\')).'%']))
            ->when($filters['vat_number'] ?? null, fn ($query, string $value) => $query->where(fn ($inner) => $inner->where('counterpart_vat_number', $value)->orWhere('own_vat_number', $value)))
            ->when($filters['date_from'] ?? null, fn ($query, string $value) => $query->whereDate('document_date', '>=', $value))
            ->when($filters['date_to'] ?? null, fn ($query, string $value) => $query->whereDate('document_date', '<=', $value))
            ->when($filters['sha256'] ?? null, fn ($query, string $value) => $query->where('sha256', strtolower($value)))
            ->orderByDesc('document_date')
            ->orderBy('sequence')
            ->gridPaginate((int) ($filters['per_page'] ?? 25));

        $this->logger->record('search', null, array_filter($filters) + ['results' => $page->total()]);

        return $this->successResponse(PreservationItemResource::collection($page->getCollection()), 'Preserved documents retrieved', meta: ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]);
    }

    #[OA\Get(path: '/api/v1/document-trust/preservation-settings', operationId: 'getPreservationSettings', description: 'Retention years per document class. The suggested defaults are commonly cited periods NOT verified against the law: the accountant must confirm them. For owners and administrators.', summary: 'Show preservation settings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Document Trust'], responses: [
        new OA\Response(response: 200, description: 'The settings', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    /**
     * Show the retention settings.
     */
    public function settings(): JsonResponse
    {
        Gate::authorize('manage', PreservationPackage::class);

        return $this->successResponse($this->retention->overview(), 'Preservation settings retrieved');
    }

    #[OA\Put(path: '/api/v1/document-trust/preservation-settings', operationId: 'updatePreservationSettings', description: 'Sets the retention years of one or more document classes. Set `confirmed_by_accountant` to record that the accountant confirmed the periods; changing a period without it clears the confirmation. Periods apply to packages closed afterwards (a closed package keeps the period it was closed with). For owners and administrators.', summary: 'Update preservation settings', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'retention_years', type: 'object', example: ['issued_invoice' => 10], additionalProperties: new OA\AdditionalProperties(type: 'integer', minimum: 1, maximum: 100)),
        new OA\Property(property: 'confirmed_by_accountant', type: 'boolean'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'timestamp', type: 'object', nullable: true, description: 'RFC 3161 time-stamp authority of the tenant. `tsa_url` must be https and a public address (null removes the authority and its credentials); the optional basic-auth credentials are stored encrypted and never returned. `tsa_declared_qualified` is the tenant declaration that the TSA is a qualified provider; it is never assumed. When an authority is set, closing a package uses it and fails (package stays open) if it does not answer validly.', properties: [
            new OA\Property(property: 'tsa_url', type: 'string', format: 'uri', nullable: true),
            new OA\Property(property: 'tsa_username', type: 'string', nullable: true),
            new OA\Property(property: 'tsa_password', type: 'string', nullable: true),
            new OA\Property(property: 'tsa_provider_name', type: 'string', nullable: true),
            new OA\Property(property: 'tsa_declared_qualified', type: 'boolean'),
        ]),
    ])), tags: ['Document Trust'], responses: [
        new OA\Response(response: 200, description: 'Settings saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    /**
     * Update the retention settings.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        Gate::authorize('manage', PreservationPackage::class);
        $data = $request->validate([
            'retention_years' => ['nullable', 'array'],
            'retention_years.*' => ['integer', 'min:1', 'max:100'],
            'confirmed_by_accountant' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'timestamp' => ['nullable', 'array'],
            'timestamp.tsa_url' => ['nullable', 'string', 'max:2048', new PublicHttpUrl(['https'])],
            'timestamp.tsa_username' => ['nullable', 'string', 'max:255'],
            'timestamp.tsa_password' => ['nullable', 'string', 'max:255'],
            'timestamp.tsa_provider_name' => ['nullable', 'string', 'max:120'],
            'timestamp.tsa_declared_qualified' => ['nullable', 'boolean'],
        ]);

        if (isset($data['timestamp'])) {
            $this->retention->updateTimestamp(array_intersect_key($request->input('timestamp'), array_flip(['tsa_url', 'tsa_username', 'tsa_password', 'tsa_provider_name', 'tsa_declared_qualified'])));
            $this->logger->record('settings', null, ['timestamp_authority' => $data['timestamp']['tsa_url'] ?? null, 'declared_qualified' => (bool) ($data['timestamp']['tsa_declared_qualified'] ?? false)], subject: PreservationSetting::firstOrFail());
        }

        $years = (array) ($data['retention_years'] ?? []);
        $unknown = array_diff(array_keys($years), PreservationDocumentClass::values());

        if ($unknown !== []) {
            throw ValidationException::withMessages(['retention_years' => __('document_trust_errors.retention.unknown_document_class', ['classes' => implode(', ', $unknown)])]);
        }

        $settings = $this->retention->update(array_map('intval', $years), (bool) ($data['confirmed_by_accountant'] ?? false), $data['notes'] ?? null, $request->user());
        $this->logger->record('settings', null, ['retention_years' => $years, 'confirmed' => (bool) ($data['confirmed_by_accountant'] ?? false)], subject: $settings);

        return $this->successResponse($this->retention->overview(), 'Preservation settings saved');
    }

    #[OA\Get(path: '/api/v1/document-trust/preservation-access-logs', operationId: 'listPreservationAccessLogs', description: 'The append-only log of every access to the preservation archive (list, view, search, add_item, close, verify, export, settings). For owners and administrators.', summary: 'List preservation access logs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Document Trust'], parameters: [
        new OA\Parameter(name: 'package_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
    ], responses: [
        new OA\Response(response: 200, description: 'The log entries, newest first', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    /**
     * List the access log.
     */
    public function accessLogs(Request $request): JsonResponse
    {
        Gate::authorize('manage', PreservationPackage::class);
        $filters = $request->validate(['package_id' => ['nullable', 'uuid'], 'action' => ['nullable', 'string', 'max:30'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:200']]);

        $page = PreservationAccessLog::query()
            ->when($filters['package_id'] ?? null, fn ($query, string $value) => $query->where('package_id', $value))
            ->when($filters['action'] ?? null, fn ($query, string $value) => $query->where('action', $value))
            ->orderByDesc('created_at')
            ->gridPaginate((int) ($filters['per_page'] ?? 50));

        return $this->successResponse($page->getCollection()->map(fn (PreservationAccessLog $log): array => [
            'id' => (string) $log->id,
            'package_id' => $log->package_id,
            'user_id' => $log->user_id,
            'action' => $log->action,
            'ip_address' => $log->ip_address,
            'details' => $log->details,
            'created_at' => $log->created_at->toIso8601String(),
        ])->all(), 'Access log retrieved', meta: ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]);
    }

    /**
     * @param  callable(): JsonResponse  $operation
     */
    private function guarded(callable $operation): JsonResponse
    {
        try {
            return $operation();
        } catch (DocumentTrustException $exception) {
            throw ValidationException::withMessages(['package' => $exception->getMessage()]);
        }
    }
}
