<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Modules\Quality\Http\Controllers\Concerns\InteractsWithQuality;
use Modules\Quality\Http\Resources\ProcedureResource;
use Modules\Quality\Models\QmsProcedure;
use Modules\Quality\Services\ProcedureService;
use OpenApi\Attributes as OA;

/**
 * Document-controlled procedures. The text is a document of the Documents module, referenced by id only.
 */
final class ProcedureController extends Controller
{
    use InteractsWithQuality;

    public function __construct(private readonly ProcedureService $procedures) {}

    #[OA\Get(path: '/api/v1/quality/procedures', operationId: 'listQmsProcedures', description: 'Paginated controlled procedures by code and version. Filters: status, code, effective=1 (approved versions only).', summary: 'List procedures', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'pending_approval', 'approved', 'rejected', 'obsolete'])),
        new OA\Parameter(name: 'code', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'effective', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Procedures', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/QmsProcedure'))])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', QmsProcedure::class);

        return ProcedureResource::collection(
            QmsProcedure::query()
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->filled('code'), static fn ($query) => $query->where('code', (string) $request->query('code')))
                ->when($request->boolean('effective'), static fn ($query) => $query->where('status', 'approved'))
                ->orderBy('code')
                ->orderByDesc('version')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/quality/procedures', operationId: 'storeQmsProcedure', description: 'Creates a controlled procedure as draft version 1. document_id is a document of the Documents module of the company, referenced by id only.', summary: 'Create a procedure', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsProcedureRequest')), tags: ['Quality'], responses: [
        new OA\Response(response: 201, description: 'Procedure created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsProcedure')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', QmsProcedure::class);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:4000'],
            'document_id' => ['nullable', 'uuid'],
            'owner_id' => ['nullable', 'uuid', Rule::exists('tenant_user', 'user_id')->where('tenant_id', $this->tenantId())],
            'review_due_date' => ['nullable', 'date'],
        ]);

        return (new ProcedureResource($this->procedures->create($this->tenantId(), $validated, $this->userId())))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/quality/procedures/{id}', operationId: 'showQmsProcedure', description: 'A controlled procedure.', summary: 'Show a procedure', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Procedure', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsProcedure')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
    ])]
    public function show(string $id): ProcedureResource
    {
        $procedure = $this->find($id);
        $this->authorize('view', $procedure);

        return new ProcedureResource($procedure);
    }

    #[OA\Put(path: '/api/v1/quality/procedures/{id}', operationId: 'updateQmsProcedure', description: 'Changes a draft or rejected procedure. Code and version never change: revise an approved procedure to get a new version.', summary: 'Update a procedure', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsProcedureUpdateRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Procedure updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsProcedure')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function update(Request $request, string $id): ProcedureResource
    {
        $procedure = $this->find($id);
        $this->authorize('update', $procedure);
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:190'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'document_id' => ['sometimes', 'nullable', 'uuid'],
            'owner_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('tenant_user', 'user_id')->where('tenant_id', $this->tenantId())],
            'review_due_date' => ['sometimes', 'nullable', 'date'],
        ]);

        return new ProcedureResource($this->procedures->update($procedure, $validated));
    }

    #[OA\Post(path: '/api/v1/quality/procedures/{id}/submit', operationId: 'submitQmsProcedure', description: 'Submits a draft or rejected procedure for approval. The document must be linked. When the company has an active workflow definition quality_procedure_approval it is started and decides; otherwise the procedure waits for a direct decision (approve or reject) of an owner, an administrator or a member granted quality.approve other than the author.', summary: 'Submit a procedure for approval', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Procedure pending approval', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsProcedure')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function submit(string $id): ProcedureResource
    {
        $procedure = $this->find($id);
        $this->authorize('update', $procedure);

        return new ProcedureResource($this->procedures->submit($procedure, $this->userId()));
    }

    #[OA\Post(path: '/api/v1/quality/procedures/{id}/approve', operationId: 'approveQmsProcedure', description: 'Direct approval of a procedure pending approval when no workflow decides it. The author cannot approve; the previous approved version of the same code becomes obsolete.', summary: 'Approve a procedure', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Procedure approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsProcedure')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function approve(string $id): ProcedureResource
    {
        $procedure = $this->find($id);
        $this->authorize('approve', $procedure);

        return new ProcedureResource($this->procedures->approve($procedure, $this->userId()));
    }

    #[OA\Post(path: '/api/v1/quality/procedures/{id}/reject', operationId: 'rejectQmsProcedure', description: 'Direct rejection of a procedure pending approval when no workflow decides it, with the reason.', summary: 'Reject a procedure', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsCancelRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Procedure rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsProcedure')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function reject(Request $request, string $id): ProcedureResource
    {
        $procedure = $this->find($id);
        $this->authorize('approve', $procedure);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return new ProcedureResource($this->procedures->reject($procedure, $validated['reason'], $this->userId()));
    }

    #[OA\Post(path: '/api/v1/quality/procedures/{id}/revise', operationId: 'reviseQmsProcedure', description: 'Creates the next draft version (same code, version + 1) of an approved procedure. The approved version stays effective until the new one is approved.', summary: 'Revise a procedure', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 201, description: 'New draft version', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsProcedure')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function revise(string $id): JsonResponse
    {
        $procedure = $this->find($id);
        $this->authorize('update', $procedure);

        return (new ProcedureResource($this->procedures->revise($procedure, $this->userId())))->response()->setStatusCode(201);
    }

    #[OA\Post(path: '/api/v1/quality/procedures/{id}/obsolete', operationId: 'obsoleteQmsProcedure', description: 'Withdraws an approved procedure.', summary: 'Make a procedure obsolete', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Procedure obsolete', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsProcedure')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function obsolete(string $id): ProcedureResource
    {
        $procedure = $this->find($id);
        $this->authorize('approve', $procedure);

        return new ProcedureResource($this->procedures->makeObsolete($procedure));
    }

    private function find(string $id): QmsProcedure
    {
        return QmsProcedure::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
