<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Contracts\Http\Controllers\Concerns\InteractsWithContractAuthoring;
use Modules\Contracts\Http\Requests\AddClauseVersionRequest;
use Modules\Contracts\Http\Requests\ClauseStatusRequest;
use Modules\Contracts\Http\Requests\SaveClauseRequest;
use Modules\Contracts\Http\Requests\SaveFallbackRequest;
use Modules\Contracts\Http\Resources\ClauseResource;
use Modules\Contracts\Models\ContractClause;
use Modules\Contracts\Services\ClauseLibrary;
use OpenApi\Attributes as OA;

/**
 * The clause library: clauses with immutable versions, an approval status and ranked fallback wordings.
 */
final class ClauseController extends Controller
{
    use InteractsWithContractAuthoring;

    public function __construct(private readonly ClauseLibrary $library) {}

    #[OA\Get(path: '/api/v1/contract-authoring/clauses', operationId: 'listContractClauses', description: 'Clauses of the library, by code. Filters: status, category, q (code or title).', summary: 'List clauses', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Contract Authoring'], responses: [
        new OA\Response(response: 200, description: 'Clauses', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ContractClauseResource'))])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ContractClause::class);
        $filters = $request->validate(['status' => ['nullable', 'in:'.implode(',', ContractClause::Statuses)], 'category' => ['nullable', 'string', 'max:60'], 'q' => ['nullable', 'string', 'max:100']]);

        return ClauseResource::collection(
            ContractClause::query()
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['category']), static fn ($query) => $query->where('category', $filters['category']))
                ->when(isset($filters['q']), static fn ($query) => $query->where(static fn ($inner) => $inner->where('code', 'like', '%'.$filters['q'].'%')->orWhere('title', 'like', '%'.$filters['q'].'%')))
                ->orderBy('code')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/contract-authoring/clauses', operationId: 'storeContractClause', description: 'Create a clause with its first wording (version 1, status draft).', summary: 'Create a clause', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractClauseRequest')), tags: ['Contract Authoring'], responses: [
        new OA\Response(response: 201, description: 'Clause created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractClauseResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function store(SaveClauseRequest $request): JsonResponse
    {
        $this->authorize('create', ContractClause::class);
        $clause = $this->library->create($request->validated(), $this->userId());

        return (new ClauseResource($clause->load(['versions', 'fallbacks'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/contract-authoring/clauses/{id}', operationId: 'showContractClause', description: 'The clause with every version and fallback.', summary: 'Show a clause', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Clause', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractClauseResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
    ])]
    public function show(string $id): ClauseResource
    {
        $clause = ContractClause::query()->with(['versions', 'fallbacks'])->findOrFail($id);
        $this->authorize('view', $clause);

        return new ClauseResource($clause);
    }

    #[OA\Put(path: '/api/v1/contract-authoring/clauses/{id}', operationId: 'updateContractClause', description: 'Change code, title or category; the wording changes through new versions.', summary: 'Update a clause', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractClauseRequest')), tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Clause updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractClauseResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function update(SaveClauseRequest $request, string $id): ClauseResource
    {
        $clause = ContractClause::query()->findOrFail($id);
        $this->authorize('update', $clause);
        $clause->update($request->validated());

        return new ClauseResource($clause->refresh()->load(['versions', 'fallbacks']));
    }

    #[OA\Post(path: '/api/v1/contract-authoring/clauses/{id}/versions', operationId: 'addContractClauseVersion', description: 'Add a new wording; earlier versions stay unchanged. A retired clause takes no versions.', summary: 'Add a clause version', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractClauseVersionRequest')), tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 201, description: 'Clause with the new version', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractClauseResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function addVersion(AddClauseVersionRequest $request, string $id): JsonResponse
    {
        $clause = ContractClause::query()->findOrFail($id);
        $this->authorize('update', $clause);
        $data = $request->validated();

        return (new ClauseResource($this->library->addVersion($clause, $data['body'], $data['change_note'] ?? null, $this->userId())->load(['versions', 'fallbacks'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/contract-authoring/clauses/{id}/status', operationId: 'transitionContractClause', description: 'Move the clause to draft, approved or retired. A clause without a version cannot be approved; a retired one is final.', summary: 'Approve or retire a clause', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractClauseStatusRequest')), tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Clause', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractClauseResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function transition(ClauseStatusRequest $request, string $id): ClauseResource
    {
        $clause = ContractClause::query()->findOrFail($id);
        $this->authorize('update', $clause);

        return new ClauseResource($this->library->transition($clause, $request->validated('status'))->load(['versions', 'fallbacks']));
    }

    #[OA\Post(path: '/api/v1/contract-authoring/clauses/{id}/fallbacks', operationId: 'addContractClauseFallback', description: 'Add a ranked fallback wording (rank 1 is tried first; the next free rank when omitted).', summary: 'Add a fallback', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractClauseFallbackRequest')), tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 201, description: 'Clause with the fallback', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractClauseResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function addFallback(SaveFallbackRequest $request, string $id): JsonResponse
    {
        $clause = ContractClause::query()->findOrFail($id);
        $this->authorize('update', $clause);
        $this->library->addFallback($clause, $request->validated());

        return (new ClauseResource($clause->refresh()->load(['versions', 'fallbacks'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Delete(path: '/api/v1/contract-authoring/clauses/{id}/fallbacks/{fallbackId}', operationId: 'deleteContractClauseFallback', description: 'Remove a fallback wording (soft delete).', summary: 'Delete a fallback', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'fallbackId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 204, description: 'Fallback deleted'),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
    ])]
    public function destroyFallback(string $id, string $fallbackId): Response
    {
        $clause = ContractClause::query()->findOrFail($id);
        $this->authorize('update', $clause);
        $clause->fallbacks()->findOrFail($fallbackId)->delete();

        return response()->noContent();
    }
}
