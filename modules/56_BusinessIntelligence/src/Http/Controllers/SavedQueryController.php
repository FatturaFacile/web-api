<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\BusinessIntelligence\Http\Controllers\Concerns\InteractsWithBi;
use Modules\BusinessIntelligence\Http\Requests\AdHocQueryRequest;
use Modules\BusinessIntelligence\Http\Requests\SavedQueryRequest;
use Modules\BusinessIntelligence\Http\Resources\SavedQueryResource;
use Modules\BusinessIntelligence\Models\BiSavedQuery;
use Modules\BusinessIntelligence\Services\SavedQueryService;
use Modules\BusinessIntelligence\Services\SharingService;
use OpenApi\Attributes as OA;

/**
 * Saved self-service queries and pivots, and the explorer that runs a query without saving it.
 */
final class SavedQueryController extends Controller
{
    use InteractsWithBi;

    public function __construct(private readonly SavedQueryService $queries, private readonly SharingService $sharing) {}

    #[OA\Get(path: '/api/v1/business-intelligence/saved-queries', operationId: 'listBiSavedQueries', description: 'Saved queries the caller can open: their own, the ones shared with the company and the ones shared with them. Filters: dataset, search (name).', summary: 'List saved queries', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], responses: [
        new OA\Response(response: 200, description: 'Saved queries', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BiSavedQuery'))])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BiSavedQuery::class);
        $filters = $request->validate(['dataset' => ['nullable', 'string', 'max:40'], 'search' => ['nullable', 'string', 'max:100']]);

        return SavedQueryResource::collection(
            BiSavedQuery::query()
                ->visibleTo($this->currentUser())
                ->with('owner:id,name')
                ->when(isset($filters['dataset']), static fn ($query) => $query->where('dataset', $filters['dataset']))
                ->when(isset($filters['search']), static fn ($query) => $query->where('name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%'))
                ->orderBy('name')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/business-intelligence/saved-queries', operationId: 'storeBiSavedQuery', description: 'Save a query on a dataset the caller can read. The specification is validated against the dataset (every field must be a dimension or measure it declares) and the pivot layout against the query. An optional snapshot frequency takes a snapshot on a schedule.', summary: 'Create a saved query', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BiSavedQueryRequest')), tags: ['Business Intelligence'], responses: [
        new OA\Response(response: 201, description: 'Query saved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiSavedQuery')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function store(SavedQueryRequest $request): JsonResponse
    {
        $this->authorize('create', BiSavedQuery::class);
        $query = $this->queries->create($this->tenantId(), $this->currentUser(), $request->validated());

        return (new SavedQueryResource($query))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/business-intelligence/saved-queries/{id}', operationId: 'showBiSavedQuery', description: 'Return a saved query the caller can open.', summary: 'Show a saved query', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 200, description: 'Saved query', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiSavedQuery')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
    ])]
    public function show(string $id): SavedQueryResource
    {
        $query = BiSavedQuery::query()->with('owner:id,name')->findOrFail($id);
        $this->authorize('view', $query);

        return new SavedQueryResource($query);
    }

    #[OA\Put(path: '/api/v1/business-intelligence/saved-queries/{id}', operationId: 'updateBiSavedQuery', description: 'Change a saved query. Allowed to the owner and to members it is shared with for editing.', summary: 'Update a saved query', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BiSavedQueryRequest')), tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 200, description: 'Query updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiSavedQuery')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function update(SavedQueryRequest $request, string $id): SavedQueryResource
    {
        $query = BiSavedQuery::query()->findOrFail($id);
        $this->authorize('update', $query);
        $updated = $this->queries->update($query, $this->currentUser(), $request->validated());
        $this->sharing->syncVisibility($updated);

        return new SavedQueryResource($updated->refresh());
    }

    #[OA\Delete(path: '/api/v1/business-intelligence/saved-queries/{id}', operationId: 'deleteBiSavedQuery', description: 'Delete a saved query and its shares. Only the owner, or an owner or administrator of the company.', summary: 'Delete a saved query', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 204, description: 'Query deleted'),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $query = BiSavedQuery::query()->findOrFail($id);
        $this->authorize('delete', $query);
        $this->sharing->purge($query);
        $query->delete();

        return response()->noContent();
    }

    #[OA\Get(path: '/api/v1/business-intelligence/saved-queries/{id}/run', operationId: 'runBiSavedQuery', description: 'Runs the saved query with the permissions and the row-level security rules of the caller, always on the active company, and returns the rows and, when the query has a pivot layout, the pivot table. Cancelled with a 422 when it takes longer than the time limit.', summary: 'Run a saved query', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiQueryOutcome')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function run(string $id): JsonResponse
    {
        $query = BiSavedQuery::query()->findOrFail($id);
        $this->authorize('view', $query);

        return response()->json(['data' => $this->queries->run($query, $this->currentUser())]);
    }

    #[OA\Post(path: '/api/v1/business-intelligence/explorer/run', operationId: 'runBiExplorerQuery', description: 'Runs a query of the pivot explorer that is not saved. Same validation and limits as a saved query. It is a POST, so it needs write access to the module.', summary: 'Run an explorer query', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BiExplorerRequest')), tags: ['Business Intelligence'], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiQueryOutcome')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function explore(AdHocQueryRequest $request): JsonResponse
    {
        $this->authorize('create', BiSavedQuery::class);
        $data = $request->validated();
        $pivot = ! empty($data['pivot']) ? (array) $data['pivot'] : null;

        return response()->json(['data' => $this->queries->runAdHoc((string) $data['dataset'], (array) $data['spec'], array_values((array) ($data['calculated_measures'] ?? [])), $pivot, $this->currentUser())]);
    }
}
