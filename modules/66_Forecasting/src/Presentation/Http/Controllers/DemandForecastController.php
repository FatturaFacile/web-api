<?php

declare(strict_types=1);

namespace Modules\Forecasting\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Forecasting\Application\Commands\ApplyAiRecommendedReorderRulesCommand;
use Modules\Forecasting\Application\Commands\GenerateSkuDemandForecastCommand;
use Modules\Forecasting\Application\Handlers\ApplyAiRecommendedReorderRulesHandler;
use Modules\Forecasting\Application\Handlers\GenerateSkuDemandForecastHandler;
use Modules\Forecasting\Application\Handlers\GetSkuForecastVsActualAccuracyHandler;
use Modules\Forecasting\Application\Handlers\ListSkuDemandForecastsHandler;
use Modules\Forecasting\Application\Queries\GetSkuForecastVsActualAccuracyQuery;
use Modules\Forecasting\Domain\Services\NormalDistribution;
use Modules\Forecasting\Presentation\Http\Requests\ApplyReorderRulesRequest;
use Modules\Forecasting\Presentation\Http\Requests\DemandFilterRequest;
use Modules\Forecasting\Presentation\Http\Requests\GenerateDemandForecastRequest;
use Modules\Forecasting\Presentation\Http\Resources\SkuDemandForecastResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class DemandForecastController extends ForecastingApiController
{
    #[OA\Get(path: '/api/v1/forecasting/demand/forecasts', operationId: 'listForecastingDemandForecasts', description: 'The latest forecast of every product and warehouse next to the current stock and thresholds, with 12 months of sales history.', summary: 'List the SKU demand forecasts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Forecasting'], parameters: [
        new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
    ], responses: [new OA\Response(response: 200, description: 'The forecasts', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function index(DemandFilterRequest $request, ListSkuDemandForecastsHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle($this->tenantId(), $data['warehouse_id'] ?? null, $data['search'] ?? null)));
    }

    #[OA\Post(path: '/api/v1/forecasting/demand/generate', operationId: 'generateForecastingDemand', description: 'Forecasts demand from the trailing 24 months of sales and sizes safety stock and reorder point from the supplier lead times.', summary: 'Generate SKU demand forecasts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Forecasting'], responses: [new OA\Response(response: 201, description: 'The forecasts', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function generate(GenerateDemandForecastRequest $request, GenerateSkuDemandForecastHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(function () use ($data, $handler): JsonResponse {
            $forecasts = $handler->handle(new GenerateSkuDemandForecastCommand(
                $this->tenantId(),
                $data['product_id'] ?? null,
                $data['warehouse_id'] ?? null,
                (int) ($data['periods_ahead'] ?? 1),
                isset($data['service_level']) ? NormalDistribution::quantile((float) $data['service_level']) : NormalDistribution::Z95,
            ));

            return $this->successResponse(SkuDemandForecastResource::collection($forecasts)->resolve(), 'Demand forecasted', Response::HTTP_CREATED);
        });
    }

    #[OA\Post(path: '/api/v1/forecasting/demand/apply-reorder-rules', operationId: 'applyForecastingReorderRules', description: 'Writes the recommended safety stock and reorder point to the warehouse replenishment rules and the planning items, with an audit entry.', summary: 'Apply the recommended reorder rules to WMS and MPS', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Forecasting'], responses: [
        new OA\Response(response: 200, description: 'The applied thresholds', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'A forecast does not exist', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function applyReorderRules(ApplyReorderRulesRequest $request, ApplyAiRecommendedReorderRulesHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse(
            $handler->handle(new ApplyAiRecommendedReorderRulesCommand($this->tenantId(), $data['forecast_ids'] ?? [], $data['product_ids'] ?? [])),
            'Reorder rules applied',
        ));
    }

    #[OA\Get(path: '/api/v1/forecasting/demand/accuracy', operationId: 'getForecastingDemandAccuracy', description: 'MAPE and WAPE of the forecasts of closed periods against the quantities sold, overall, by warehouse and by SKU.', summary: 'Get the forecast accuracy', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Forecasting'], parameters: [
        new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [new OA\Response(response: 200, description: 'The accuracy', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function accuracy(DemandFilterRequest $request, GetSkuForecastVsActualAccuracyHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new GetSkuForecastVsActualAccuracyQuery(
            $this->tenantId(),
            $data['product_id'] ?? null,
            $data['warehouse_id'] ?? null,
        ))));
    }
}
