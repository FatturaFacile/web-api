<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Wms\Http\Controllers\Concerns\InteractsWithWms;
use Modules\Wms\Http\Requests\WaveRuleRequest;
use Modules\Wms\Http\Resources\WaveRuleResource;
use Modules\Wms\Models\WmsWaveRule;
use Modules\Wms\Services\WaveRuleService;
use OpenApi\Attributes as OA;

/**
 * Wave rules of the active company.
 */
final class WaveRuleController extends Controller
{
    use InteractsWithWms;

    public function __construct(private readonly WaveRuleService $service) {}

    #[OA\Get(path: '/api/v1/wms/wave-rules', operationId: 'listWmsWaveRules', description: 'Wave rules of the active company. Filters: warehouse_id, is_active, search.', summary: 'List wave rules', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')), new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Wave rules', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsWaveRule'))])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WmsWaveRule::class);
        $filters = $request->validate(['warehouse_id' => ['nullable', 'uuid'], 'is_active' => ['nullable', 'boolean'], 'search' => ['nullable', 'string', 'max:100']]);

        return WaveRuleResource::collection(
            WmsWaveRule::query()
                ->with(['warehouse:id,code,name'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['warehouse_id']), static fn ($query) => $query->where('warehouse_id', $filters['warehouse_id']))
                ->when(isset($filters['is_active']), static fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
                ->when(isset($filters['search']), static function ($query) use ($filters): void {
                    $term = '%'.mb_strtolower(str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['search'])).'%';
                    $query->whereRaw("LOWER(COALESCE(name, '')) LIKE ? ESCAPE '!'", [$term]);
                })
                ->orderBy('priority')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/wms/wave-rules', operationId: 'storeWmsWaveRule', description: 'Create a wave rule.', summary: 'Create a wave rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WmsWaveRuleRequest')), responses: [
        new OA\Response(response: 201, description: 'Wave rule created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsWaveRule')])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function store(WaveRuleRequest $request): JsonResponse
    {
        $this->authorize('create', WmsWaveRule::class);

        return (new WaveRuleResource($this->service->create($this->tenantId(), $request->validated())->load(['warehouse:id,code,name'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/wms/wave-rules/{id}', operationId: 'showWmsWaveRule', description: 'Return a wave rule.', summary: 'Show a wave rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Wave rule', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsWaveRule')])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubNotFound', response: 404),
    ])]
    public function show(string $id): WaveRuleResource
    {
        $record = WmsWaveRule::query()->with(['warehouse:id,code,name'])->findOrFail($id);
        $this->authorize('view', $record);

        return new WaveRuleResource($record);
    }

    #[OA\Put(path: '/api/v1/wms/wave-rules/{id}', operationId: 'updateWmsWaveRule', description: 'Change a wave rule; only the fields sent are validated and changed.', summary: 'Update a wave rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WmsWaveRuleRequest')), responses: [
        new OA\Response(response: 200, description: 'Wave rule updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsWaveRule')])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function update(WaveRuleRequest $request, string $id): WaveRuleResource
    {
        $record = WmsWaveRule::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new WaveRuleResource($this->service->update($record, $request->validated())->load(['warehouse:id,code,name']));
    }

    #[OA\Delete(path: '/api/v1/wms/wave-rules/{id}', operationId: 'deleteWmsWaveRule', description: 'Delete a wave rule (soft delete).', summary: 'Delete a wave rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 204, description: 'Wave rule deleted'),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $record = WmsWaveRule::query()->findOrFail($id);
        $this->authorize('delete', $record);
        $this->service->delete($record);

        return response()->noContent();
    }
}
