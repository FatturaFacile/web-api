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
use Modules\Wms\Http\Requests\SlottingRuleRequest;
use Modules\Wms\Http\Resources\SlottingRuleResource;
use Modules\Wms\Models\WmsSlottingRule;
use Modules\Wms\Services\SlottingRuleService;
use OpenApi\Attributes as OA;

/**
 * Slotting rules of the active company.
 */
final class SlottingRuleController extends Controller
{
    use InteractsWithWms;

    public function __construct(private readonly SlottingRuleService $service) {}

    #[OA\Get(path: '/api/v1/wms/slotting-rules', operationId: 'listWmsSlottingRules', description: 'Slotting rules of the active company. Filters: warehouse_id, abc_class, is_active, search.', summary: 'List slotting rules', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'abc_class', in: 'query', schema: new OA\Schema(type: 'string', enum: ['A', 'B', 'C'])), new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')), new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Slotting rules', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsSlottingRule'))])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WmsSlottingRule::class);
        $filters = $request->validate(['warehouse_id' => ['nullable', 'uuid'], 'abc_class' => ['nullable', Rule::in(WmsSlottingRule::AbcClasses)], 'is_active' => ['nullable', 'boolean'], 'search' => ['nullable', 'string', 'max:100']]);

        return SlottingRuleResource::collection(
            WmsSlottingRule::query()
                ->with(['warehouse:id,code,name'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['warehouse_id']), static fn ($query) => $query->where('warehouse_id', $filters['warehouse_id']))
                ->when(isset($filters['abc_class']), static fn ($query) => $query->where('abc_class', $filters['abc_class']))
                ->when(isset($filters['is_active']), static fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
                ->when(isset($filters['search']), static function ($query) use ($filters): void {
                    $term = '%'.mb_strtolower(str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['search'])).'%';
                    $query->whereRaw("LOWER(COALESCE(name, '')) LIKE ? ESCAPE '!'", [$term]);
                })
                ->orderBy('priority')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/wms/slotting-rules', operationId: 'storeWmsSlottingRule', description: 'Create a slotting rule.', summary: 'Create a slotting rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WmsSlottingRuleRequest')), responses: [
        new OA\Response(response: 201, description: 'Slotting rule created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsSlottingRule')])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function store(SlottingRuleRequest $request): JsonResponse
    {
        $this->authorize('create', WmsSlottingRule::class);

        return (new SlottingRuleResource($this->service->create($this->tenantId(), $request->validated())->load(['warehouse:id,code,name'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/wms/slotting-rules/{id}', operationId: 'showWmsSlottingRule', description: 'Return a slotting rule.', summary: 'Show a slotting rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Slotting rule', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsSlottingRule')])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubNotFound', response: 404),
    ])]
    public function show(string $id): SlottingRuleResource
    {
        $record = WmsSlottingRule::query()->with(['warehouse:id,code,name'])->findOrFail($id);
        $this->authorize('view', $record);

        return new SlottingRuleResource($record);
    }

    #[OA\Put(path: '/api/v1/wms/slotting-rules/{id}', operationId: 'updateWmsSlottingRule', description: 'Change a slotting rule; only the fields sent are validated and changed.', summary: 'Update a slotting rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WmsSlottingRuleRequest')), responses: [
        new OA\Response(response: 200, description: 'Slotting rule updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsSlottingRule')])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function update(SlottingRuleRequest $request, string $id): SlottingRuleResource
    {
        $record = WmsSlottingRule::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new SlottingRuleResource($this->service->update($record, $request->validated())->load(['warehouse:id,code,name']));
    }

    #[OA\Delete(path: '/api/v1/wms/slotting-rules/{id}', operationId: 'deleteWmsSlottingRule', description: 'Delete a slotting rule (soft delete).', summary: 'Delete a slotting rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 204, description: 'Slotting rule deleted'),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $record = WmsSlottingRule::query()->findOrFail($id);
        $this->authorize('delete', $record);
        $this->service->delete($record);

        return response()->noContent();
    }
}
