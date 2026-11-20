<?php

declare(strict_types=1);

namespace Modules\Forecasting\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Forecasting\Application\Commands\RunAiLiquiditySimulationCommand;
use Modules\Forecasting\Application\DTOs\WhatIfScenarioDto;
use Modules\Forecasting\Application\Handlers\GetAiCashFlowConfidenceBandsHandler;
use Modules\Forecasting\Application\Handlers\RunAiLiquiditySimulationHandler;
use Modules\Forecasting\Application\Queries\GetAiCashFlowConfidenceBandsQuery;
use Modules\Forecasting\Presentation\Http\Requests\ConfidenceBandsRequest;
use Modules\Forecasting\Presentation\Http\Requests\SimulateLiquidityRequest;
use Modules\Forecasting\Presentation\Http\Resources\LiquidityForecastRunResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class LiquidityForecastController extends ForecastingApiController
{
    #[OA\Post(path: '/api/v1/forecasting/liquidity/simulate', operationId: 'simulateForecastingLiquidity', description: 'Projects the daily cash balance as P10/P50/P90 for 91 or 365 days, optionally with what-if overrides. A preview is not stored.', summary: 'Run an AI liquidity simulation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Forecasting'], responses: [
        new OA\Response(response: 201, description: 'The run', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 422, description: 'Invalid input', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function simulate(SimulateLiquidityRequest $request, RunAiLiquiditySimulationHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(function () use ($data, $handler): JsonResponse {
            $run = $handler->handle(new RunAiLiquiditySimulationCommand(
                $this->tenantId(),
                (int) $data['horizon_days'],
                isset($data['what_if']) ? WhatIfScenarioDto::fromArray($data['what_if']) : null,
                ! (bool) ($data['preview'] ?? false),
            ));

            return $this->successResponse((new LiquidityForecastRunResource($run))->resolve(), 'Liquidity simulated', Response::HTTP_CREATED);
        });
    }

    #[OA\Get(path: '/api/v1/forecasting/liquidity/confidence-bands', operationId: 'getForecastingConfidenceBands', description: 'Chart series (dates, p10, p50, p90, statutory events, key drivers) of the latest liquidity run or the named one.', summary: 'Get the cash flow confidence bands', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Forecasting'], parameters: [
        new OA\Parameter(name: 'run_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'horizon_days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: [91, 365])),
    ], responses: [
        new OA\Response(response: 200, description: 'The series', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'No run', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function confidenceBands(ConfidenceBandsRequest $request, GetAiCashFlowConfidenceBandsHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(function () use ($data, $handler): JsonResponse {
            $series = $handler->handle(new GetAiCashFlowConfidenceBandsQuery(
                $this->tenantId(),
                $data['run_id'] ?? null,
                isset($data['horizon_days']) ? (int) $data['horizon_days'] : null,
            ));

            return $series === null
                ? $this->problemDetailsResponse('Not Found', 'There is no liquidity run yet.', Response::HTTP_NOT_FOUND)
                : $this->successResponse($series);
        });
    }
}
