<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\LiquidityPlanning\Http\Requests\ManualItemRequest;
use Modules\LiquidityPlanning\Http\Resources\ManualItemResource;
use Modules\LiquidityPlanning\Models\ForecastManualItem;
use Modules\LiquidityPlanning\Services\LiquidityTexts;
use OpenApi\Attributes as OA;

/**
 * The manual forecast items: the assumptions of the CFO projected with the flows read from the ledgers.
 */
final class ManualItemController extends Controller
{
    use ApiResponse;

    #[OA\Get(path: '/api/v1/liquidity-planning/manual-items', operationId: 'listLiquidityPlanningManualItems', summary: 'Manual forecast items', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 200, description: 'The items')])]
    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(ManualItemResource::collection(ForecastManualItem::query()->orderBy('first_date')->orderBy('label')->get()), LiquidityTexts::get('lpApi.message.manual_items', LiquidityTexts::locale()));
    }

    #[OA\Post(path: '/api/v1/liquidity-planning/manual-items', operationId: 'createLiquidityPlanningManualItem', summary: 'Add a manual forecast item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 201, description: 'The item'), new OA\Response(response: 422, description: 'Not valid')])]
    public function store(ManualItemRequest $request): JsonResponse
    {
        $item = ForecastManualItem::query()->create($this->attributes($request));

        return $this->successResponse(new ManualItemResource($item), LiquidityTexts::get('lpApi.message.manual_item_saved', LiquidityTexts::locale()), 201);
    }

    #[OA\Put(path: '/api/v1/liquidity-planning/manual-items/{id}', operationId: 'updateLiquidityPlanningManualItem', summary: 'Change a manual forecast item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 200, description: 'The item')])]
    public function update(ManualItemRequest $request, string $id): JsonResponse
    {
        $item = ForecastManualItem::query()->findOrFail($id);
        $item->update($this->attributes($request));

        return $this->successResponse(new ManualItemResource($item->refresh()), LiquidityTexts::get('lpApi.message.manual_item_saved', LiquidityTexts::locale()));
    }

    #[OA\Delete(path: '/api/v1/liquidity-planning/manual-items/{id}', operationId: 'deleteLiquidityPlanningManualItem', summary: 'Remove a manual forecast item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 200, description: 'Removed')])]
    public function destroy(Request $request, string $id): JsonResponse
    {
        ForecastManualItem::query()->findOrFail($id)->delete();

        return $this->successResponse(null, LiquidityTexts::get('lpApi.message.manual_item_deleted', LiquidityTexts::locale()));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ManualItemRequest $request): array
    {
        return array_merge(['is_active' => true, 'notes' => null, 'end_date' => null], $request->validated(), ['currency' => strtoupper((string) $request->validated('currency'))]);
    }
}
