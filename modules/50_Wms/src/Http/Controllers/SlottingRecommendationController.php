<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Modules\Wms\Http\Controllers\Concerns\InteractsWithWms;
use Modules\Wms\Http\Resources\SlottingRecommendationResource;
use Modules\Wms\Models\WmsSlottingRecommendation;
use OpenApi\Attributes as OA;

/**
 * Slotting recommendations of the active company.
 */
final class SlottingRecommendationController extends Controller
{
    use InteractsWithWms;

    #[OA\Get(path: '/api/v1/wms/slotting-recommendations', operationId: 'listWmsSlottingRecommendations', description: 'Slotting recommendations of the active company. Filters: warehouse_id, status, abc_class, stock_item_id.', summary: 'List slotting recommendations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'accepted', 'rejected'])), new OA\Parameter(name: 'abc_class', in: 'query', schema: new OA\Schema(type: 'string', enum: ['A', 'B', 'C'])), new OA\Parameter(name: 'stock_item_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Slotting recommendations', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsSlottingRecommendation'))])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WmsSlottingRecommendation::class);
        $filters = $request->validate(['warehouse_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in(WmsSlottingRecommendation::Statuses)], 'abc_class' => ['nullable', Rule::in(WmsSlottingRecommendation::AbcClasses)], 'stock_item_id' => ['nullable', 'uuid']]);

        return SlottingRecommendationResource::collection(
            WmsSlottingRecommendation::query()
                ->with(['stockItem:id,sku,name', 'fromBin:id,code', 'toBin:id,code'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['warehouse_id']), static fn ($query) => $query->where('warehouse_id', $filters['warehouse_id']))
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['abc_class']), static fn ($query) => $query->where('abc_class', $filters['abc_class']))
                ->when(isset($filters['stock_item_id']), static fn ($query) => $query->where('stock_item_id', $filters['stock_item_id']))
                ->orderByDesc('created_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Get(path: '/api/v1/wms/slotting-recommendations/{id}', operationId: 'showWmsSlottingRecommendation', description: 'Return a slotting recommendation.', summary: 'Show a slotting recommendation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Slotting recommendation', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsSlottingRecommendation')])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubNotFound', response: 404),
    ])]
    public function show(string $id): SlottingRecommendationResource
    {
        $record = WmsSlottingRecommendation::query()->with(['stockItem:id,sku,name', 'fromBin:id,code', 'toBin:id,code'])->findOrFail($id);
        $this->authorize('view', $record);

        return new SlottingRecommendationResource($record);
    }
}
