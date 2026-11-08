<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\StoreItemRequest;
use Modules\Manufacturing\Http\Requests\UpdateItemRequest;
use Modules\Manufacturing\Http\Resources\MfgBomResource;
use Modules\Manufacturing\Http\Resources\MfgItemResource;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgBomComponent;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Services\ManufacturingMasterDataService;
use Modules\Manufacturing\Services\StandardCostService;
use OpenApi\Attributes as OA;

/**
 * Manufacturing items: list, create, read, update, standard cost rollup and where-used.
 */
final class ItemController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(
        private readonly ManufacturingMasterDataService $masterData,
        private readonly StandardCostService $costs,
        private readonly BillOfMaterialsServiceInterface $boms,
    ) {}

    /**
     * Paginated items of the company.
     */
    #[OA\Get(path: '/api/v1/manufacturing/items', operationId: 'listMfgItems', description: 'Paginated items of the active company, filtered by search (code or name), item type and active flag.', summary: 'List manufacturing items', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Items'],
        parameters: [new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'item_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')), new OA\Parameter(ref: '#/components/parameters/MfgPage'), new OA\Parameter(ref: '#/components/parameters/MfgPerPage')],
        responses: [new OA\Response(response: 200, description: 'Items', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgItem'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MfgItem::query()->where('tenant_id', $this->tenantId());
        if ($request->filled('search')) {
            $search = '%'.mb_strtolower((string) $request->query('search')).'%';
            $query->where(static fn ($inner) => $inner->whereRaw('LOWER(item_code) LIKE ?', [$search])->orWhereRaw('LOWER(name) LIKE ?', [$search]));
        }
        if ($request->filled('item_type')) {
            $query->where('item_type', (string) $request->query('item_type'));
        }
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return MfgItemResource::collection($query->orderBy('item_code')->paginate($this->perPage($request)));
    }

    /**
     * Creates an item.
     */
    #[OA\Post(path: '/api/v1/manufacturing/items', operationId: 'storeMfgItem', description: 'Creates a manufacturing item; link it to an inventory stock item so its stock can move.', summary: 'Create manufacturing item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Items'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgStoreItemRequest')),
        responses: [new OA\Response(response: 201, description: 'Item created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgItem')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function store(StoreItemRequest $request): JsonResponse
    {
        return (new MfgItemResource($this->masterData->createItem($this->tenantId(), $request->validated())))->response()->setStatusCode(201);
    }

    /**
     * One item.
     */
    #[OA\Get(path: '/api/v1/manufacturing/items/{id}', operationId: 'showMfgItem', description: 'Returns an item of the active company.', summary: 'Get manufacturing item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Items'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Item', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgItem')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404)])]
    public function show(string $id): MfgItemResource
    {
        return new MfgItemResource($this->owned(MfgItem::class, $id));
    }

    /**
     * Updates an item.
     */
    #[OA\Put(path: '/api/v1/manufacturing/items/{id}', operationId: 'updateMfgItem', description: 'Updates the planning data of an item; the standard cost breakdown is set by the cost rollup.', summary: 'Update manufacturing item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Items'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgUpdateItemRequest')),
        responses: [new OA\Response(response: 200, description: 'Item updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgItem')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function update(UpdateItemRequest $request, string $id): MfgItemResource
    {
        return new MfgItemResource($this->masterData->updateItem($this->owned(MfgItem::class, $id), $request->validated()));
    }

    /**
     * Rolls up and stores the standard cost of the item.
     */
    #[OA\Post(path: '/api/v1/manufacturing/items/{id}/cost-rollup', operationId: 'rollupMfgItemCost', description: 'Rolls up the standard unit cost over all BOM levels (components at their standard, labour and overhead of the effective routing at the work centre rates, subcontracting at the agreed cost) and stores it on the item and its manufactured components.', summary: 'Roll up standard cost', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Items'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId'), new OA\Parameter(name: 'date', in: 'query', required: false, description: 'Date of the BOM and routing revisions to use (default today)', schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [new OA\Response(response: 200, description: 'Cost rollup', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgCostRollup')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function costRollup(Request $request, string $id): JsonResponse
    {
        $date = $request->validate(['date' => ['nullable', 'date']])['date'] ?? null;

        return response()->json(['data' => $this->costs->rollup($this->owned(MfgItem::class, $id), $date)]);
    }

    /**
     * BOM revisions that use the item.
     */
    #[OA\Get(path: '/api/v1/manufacturing/items/{id}/where-used', operationId: 'whereUsedMfgItem', description: 'BOM revisions of the company in which the item is a component.', summary: 'Where-used of an item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Items'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'BOM revisions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgBom'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404)])]
    public function whereUsed(string $id): AnonymousResourceCollection
    {
        $boms = $this->boms->whereUsed($this->owned(MfgItem::class, $id))
            ->map(static fn (MfgBomComponent $component): ?MfgBom => $component->bom)
            ->filter()
            ->unique('id')
            ->values();

        return MfgBomResource::collection($boms);
    }
}
