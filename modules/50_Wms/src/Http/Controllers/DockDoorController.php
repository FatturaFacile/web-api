<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Wms\Http\Controllers\Concerns\InteractsWithWms;
use Modules\Wms\Http\Requests\DockDoorRequest;
use Modules\Wms\Http\Resources\DockDoorResource;
use Modules\Wms\Models\WmsDockDoor;
use Modules\Wms\Services\DockDoorService;
use OpenApi\Attributes as OA;

/**
 * Dock doors of the active company.
 */
final class DockDoorController extends Controller
{
    use InteractsWithWms;

    public function __construct(private readonly DockDoorService $service) {}

    #[OA\Get(path: '/api/v1/wms/dock-doors', operationId: 'listWmsDockDoors', description: 'Dock doors of the active company. Filters: warehouse_id, direction, is_active, search.', summary: 'List dock doors', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', enum: ['inbound', 'outbound', 'both'])), new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')), new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Dock doors', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsDockDoor'))])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WmsDockDoor::class);
        $filters = $request->validate(['warehouse_id' => ['nullable', 'uuid'], 'direction' => ['nullable', Rule::in(WmsDockDoor::Directions)], 'is_active' => ['nullable', 'boolean'], 'search' => ['nullable', 'string', 'max:100']]);

        return DockDoorResource::collection(
            WmsDockDoor::query()
                ->with(['warehouse:id,code,name'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['warehouse_id']), static fn ($query) => $query->where('warehouse_id', $filters['warehouse_id']))
                ->when(isset($filters['direction']), static fn ($query) => $query->where('direction', $filters['direction']))
                ->when(isset($filters['is_active']), static fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
                ->when(isset($filters['search']), static function ($query) use ($filters): void {
                    $term = '%'.mb_strtolower(str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['search'])).'%';
                    $query->where(static fn ($inner) => $inner
                        ->whereRaw("LOWER(COALESCE(code, '')) LIKE ? ESCAPE '!'", [$term])
                        ->orWhereRaw("LOWER(COALESCE(name, '')) LIKE ? ESCAPE '!'", [$term]));
                })
                ->orderBy('code')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/wms/dock-doors', operationId: 'storeWmsDockDoor', description: 'Create a dock door.', summary: 'Create a dock door', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WmsDockDoorRequest')), responses: [
        new OA\Response(response: 201, description: 'Dock door created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsDockDoor')])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function store(DockDoorRequest $request): JsonResponse
    {
        $this->authorize('create', WmsDockDoor::class);

        return (new DockDoorResource($this->service->create($this->tenantId(), $request->validated())->load(['warehouse:id,code,name'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/wms/dock-doors/{id}', operationId: 'showWmsDockDoor', description: 'Return a dock door.', summary: 'Show a dock door', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Dock door', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsDockDoor')])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubNotFound', response: 404),
    ])]
    public function show(string $id): DockDoorResource
    {
        $record = WmsDockDoor::query()->with(['warehouse:id,code,name'])->findOrFail($id);
        $this->authorize('view', $record);

        return new DockDoorResource($record);
    }

    #[OA\Put(path: '/api/v1/wms/dock-doors/{id}', operationId: 'updateWmsDockDoor', description: 'Change a dock door; only the fields sent are validated and changed.', summary: 'Update a dock door', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WmsDockDoorRequest')), responses: [
        new OA\Response(response: 200, description: 'Dock door updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsDockDoor')])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function update(DockDoorRequest $request, string $id): DockDoorResource
    {
        $record = WmsDockDoor::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new DockDoorResource($this->service->update($record, $request->validated())->load(['warehouse:id,code,name']));
    }

    #[OA\Delete(path: '/api/v1/wms/dock-doors/{id}', operationId: 'deleteWmsDockDoor', description: 'Delete a dock door (soft delete).', summary: 'Delete a dock door', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 204, description: 'Dock door deleted'),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $record = WmsDockDoor::query()->findOrFail($id);
        $this->authorize('delete', $record);
        $this->service->delete($record);

        return response()->noContent();
    }
}
