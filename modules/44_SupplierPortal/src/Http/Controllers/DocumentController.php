<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\StoredFileHeaders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\SupplierPortal\Http\Controllers\Concerns\InteractsWithSupplierPortal;
use Modules\SupplierPortal\Http\Requests\AcceptDocumentRequest;
use Modules\SupplierPortal\Http\Requests\DocumentRequirementRequest;
use Modules\SupplierPortal\Http\Requests\RejectDocumentRequest;
use Modules\SupplierPortal\Http\Requests\SubmitDocumentRequest;
use Modules\SupplierPortal\Http\Resources\DocumentRequirementResource;
use Modules\SupplierPortal\Http\Resources\DocumentSubmissionResource;
use Modules\SupplierPortal\Models\DocumentRequirement;
use Modules\SupplierPortal\Models\SupplierDocumentSubmission;
use Modules\SupplierPortal\Models\SupplierOnboarding;
use Modules\SupplierPortal\Services\SupplierDocumentService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Required document types and the documents of the suppliers with their review and expiry.
 */
final class DocumentController extends Controller
{
    use InteractsWithSupplierPortal;

    public function __construct(private readonly SupplierDocumentService $documents) {}

    #[OA\Get(path: '/api/v1/supplier-portal/document-requirements', operationId: 'listSupplierDocumentRequirements', description: 'Required document types of the company, by sort order. Filters: country, active=1.', summary: 'List required document types', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [
        new OA\Parameter(name: 'country', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['IT', 'CH'])),
        new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    ], responses: [
        new OA\Response(response: 200, description: 'Requirements', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SpDocumentRequirement'))])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
    ])]
    public function requirements(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DocumentRequirement::class);

        return DocumentRequirementResource::collection(
            DocumentRequirement::query()
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('country'), fn ($query) => $query->where(fn ($inner) => $inner->whereNull('country')->orWhere('country', strtoupper((string) $request->query('country')))))
                ->when($request->boolean('active'), static fn ($query) => $query->where('is_active', true))
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get()
        );
    }

    #[OA\Post(path: '/api/v1/supplier-portal/document-requirements', operationId: 'storeSupplierDocumentRequirement', description: 'Defines a required document type, for one country or all.', summary: 'Define a required document type', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SpDocumentRequirementRequest')), tags: ['Supplier Portal'], responses: [
        new OA\Response(response: 201, description: 'Requirement created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpDocumentRequirement')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function storeRequirement(DocumentRequirementRequest $request): JsonResponse
    {
        $this->authorize('create', DocumentRequirement::class);
        $requirement = DocumentRequirement::create([...$request->validated(), 'tenant_id' => $this->tenantId()]);

        return (new DocumentRequirementResource($requirement->refresh()))->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/v1/supplier-portal/document-requirements/{id}', operationId: 'updateSupplierDocumentRequirement', description: 'Changes a required document type (deactivate it with is_active=false rather than deleting it).', summary: 'Update a required document type', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SpDocumentRequirementRequest')), tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Requirement updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpDocumentRequirement')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function updateRequirement(DocumentRequirementRequest $request, string $id): DocumentRequirementResource
    {
        $requirement = DocumentRequirement::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $requirement);
        $requirement->update($request->validated());

        return new DocumentRequirementResource($requirement->refresh());
    }

    #[OA\Post(path: '/api/v1/supplier-portal/document-requirements/seed-defaults', operationId: 'seedSupplierDocumentRequirements', description: 'Creates the standard requirements the company does not have yet: DURC, visura camerale and anti-mafia certificate for Italy; Swiss UID extract, Handelsregister extract and social insurance for Switzerland; liability insurance and ISO 9001, 14001, 45001 certificates for every country. Existing codes are left alone.', summary: 'Create the default document types', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], responses: [
        new OA\Response(response: 200, description: 'Requirements created', content: new OA\JsonContent(properties: [new OA\Property(property: 'created', type: 'integer')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
    ])]
    public function seedDefaults(): JsonResponse
    {
        $this->authorize('create', DocumentRequirement::class);

        return response()->json(['created' => $this->documents->seedDefaults($this->tenantId())]);
    }

    #[OA\Get(path: '/api/v1/supplier-portal/documents', operationId: 'listSupplierDocuments', description: 'Paginated documents of the suppliers. Filters: supplier_id, requirement_id, status, expiring_within_days (accepted documents expiring within the days, expired ones included, soonest first).', summary: 'List supplier documents', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [
        new OA\Parameter(name: 'supplier_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'requirement_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['submitted', 'accepted', 'rejected', 'superseded'])),
        new OA\Parameter(name: 'expiring_within_days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 3650)),
        new OA\Parameter(ref: '#/components/parameters/SupplierPortalPerPage'),
    ], responses: [
        new OA\Response(response: 200, description: 'Documents', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SpDocumentSubmission'))])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SupplierDocumentSubmission::class);
        $request->validate([
            'status' => ['nullable', Rule::in(SupplierDocumentSubmission::Statuses)],
            'expiring_within_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ]);

        $expiring = $request->filled('expiring_within_days');

        return DocumentSubmissionResource::collection(
            SupplierDocumentSubmission::query()
                ->with(['requirement', 'supplier'])
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('supplier_id'), static fn ($query) => $query->where('supplier_id', (string) $request->query('supplier_id')))
                ->when($request->filled('requirement_id'), static fn ($query) => $query->where('requirement_id', (string) $request->query('requirement_id')))
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($expiring, static fn ($query) => $query->where('status', SupplierDocumentSubmission::Accepted)->whereNotNull('expires_at')->whereDate('expires_at', '<=', now()->addDays((int) $request->query('expiring_within_days'))->toDateString())->orderBy('expires_at'))
                ->when(! $expiring, static fn ($query) => $query->orderByDesc('created_at'))
                ->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/supplier-portal/documents', operationId: 'storeSupplierDocument', description: 'Enters a document for a supplier (multipart when a file is attached). It waits for the review. An earlier document of the same type that was not reviewed yet is superseded.', summary: 'Enter a supplier document', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/SpSubmitDocumentRequest'))), tags: ['Supplier Portal'], responses: [
        new OA\Response(response: 201, description: 'Document entered', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpDocumentSubmission')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function store(SubmitDocumentRequest $request): JsonResponse
    {
        $this->authorize('create', SupplierDocumentSubmission::class);
        $supplier = $this->supplier((string) $request->validated('supplier_id'));
        $requirement = DocumentRequirement::query()->findOrFail($request->validated('requirement_id'));
        $onboarding = SupplierOnboarding::query()->where('supplier_id', $supplier->id)->first();

        $submission = $this->documents->submit($supplier, $requirement, $request->validated(), $request->file('file'), 'internal', $this->userId(), $onboarding?->id, (string) ($onboarding?->country ?? $requirement->country ?? 'IT'));

        return (new DocumentSubmissionResource($submission->load(['requirement', 'supplier'])))->response()->setStatusCode(201);
    }

    #[OA\Post(path: '/api/v1/supplier-portal/documents/{id}/accept', operationId: 'acceptSupplierDocument', description: 'Accepts a document waiting for review. It must be valid today and carry an expiry date when the type needs one (given, or issue date plus the validity of the type). It is mirrored in the qualification documents of the procurement module and replaces the previous accepted document of the same type.', summary: 'Accept a supplier document', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/SpAcceptDocumentRequest')), tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Document accepted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpDocumentSubmission')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function accept(AcceptDocumentRequest $request, string $id): DocumentSubmissionResource
    {
        $submission = $this->find($id);
        $this->authorize('update', $submission);

        return new DocumentSubmissionResource($this->documents->accept($submission, $request->validated(), $this->userId())->load(['requirement', 'supplier']));
    }

    #[OA\Post(path: '/api/v1/supplier-portal/documents/{id}/reject', operationId: 'rejectSupplierDocument', description: 'Rejects a document waiting for review; the reason is shown to the supplier, who sends a new one.', summary: 'Reject a supplier document', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SpRejectDocumentRequest')), tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Document rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpDocumentSubmission')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function reject(RejectDocumentRequest $request, string $id): DocumentSubmissionResource
    {
        $submission = $this->find($id);
        $this->authorize('update', $submission);

        return new DocumentSubmissionResource($this->documents->reject($submission, (string) $request->validated('reason'), $this->userId())->load(['requirement', 'supplier']));
    }

    #[OA\Get(path: '/api/v1/supplier-portal/documents/{id}/download', operationId: 'downloadSupplierDocument', description: 'Downloads the file of a document as an attachment (safe type headers, no sniffing).', summary: 'Download a supplier document', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'The file', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
    ])]
    public function download(string $id): StreamedResponse
    {
        $submission = $this->find($id);
        $this->authorize('view', $submission);

        $disk = Storage::disk((string) config('supplier_portal.documents_disk'));
        abort_if($submission->file_path === null || ! $disk->exists($submission->file_path), 404);

        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($submission->original_name ?? 'document'));
        $headers = StoredFileHeaders::for($submission->mime_type, $name, false);

        return $disk->download($submission->file_path, $name, ['Content-Type' => $headers['Content-Type'], 'X-Content-Type-Options' => $headers['X-Content-Type-Options']]);
    }

    private function find(string $id): SupplierDocumentSubmission
    {
        return SupplierDocumentSubmission::query()->with(['requirement', 'supplier'])->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
