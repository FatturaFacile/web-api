<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Actions\GenerateCashForecastAction;
use Modules\Treasury\Contracts\CashForecastServiceInterface;
use Modules\Treasury\Http\Requests\GenerateForecastRequest;
use Modules\Treasury\Http\Resources\CashForecastResource;
use Modules\Treasury\Models\CashForecast;
use OpenApi\Attributes as OA;

final class CashForecastController extends Controller
{
    public function __construct(
        private readonly CashForecastServiceInterface $forecastService,
        private readonly GenerateCashForecastAction $generateAction
    ) {}

    /**
     * Returns the paginated list of cash forecasts of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/treasury/cash-forecasts',
        operationId: 'listTreasuryCashForecasts',
        description: 'Returns the paginated list of cash forecasts of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List cash forecasts',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Cash Forecasts'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of cash forecasts', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryCashForecastResource')), new OA\Property(property: 'links', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $forecasts = CashForecast::where('tenant_id', $tenantId)
            ->latest('calculation_date')
            ->paginate((int) $request->query('per_page', 25));

        return response()->json(CashForecastResource::collection($forecasts)->response()->getData(true));
    }

    /**
     * Generates a cash forecast for the scenario, horizon and calculation date.
     */
    #[OA\Post(
        path: '/api/v1/treasury/cash-forecasts/generate',
        operationId: 'generateTreasuryCashForecast',
        description: 'Generates a cash forecast for the scenario, horizon and calculation date.',
        summary: 'Generate cash forecast',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Cash Forecasts'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TreasuryGenerateForecastRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Generate created', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryCashForecastResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function generate(GenerateForecastRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $scenario = $request->input('scenario_code', 'BASELINE');
        $horizon = (int) $request->input('horizon_days', 90);
        $date = $request->input('calculation_date', now()->toDateString());

        $forecast = $this->generateAction->execute($tenantId, $scenario, $horizon, $date);

        return response()->json(new CashForecastResource($forecast), 201);
    }

    /**
     * Returns a single cash forecast of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/treasury/cash-forecasts/{id}',
        operationId: 'getTreasuryCashForecast',
        description: 'Returns a single cash forecast of the active tenant.',
        summary: 'Get cash forecast',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Cash Forecasts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the cash forecast', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryCashForecastResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $forecast = CashForecast::with('lines')->findOrFail($id);

        return response()->json(new CashForecastResource($forecast));
    }

    /**
     * Evaluates the funding deficits of a cash forecast.
     */
    #[OA\Get(
        path: '/api/v1/treasury/cash-forecasts/{id}/evaluate-deficits',
        operationId: 'getEvaluateDeficitsTreasuryCashForecast',
        description: 'Evaluates the funding deficits of a cash forecast.',
        summary: 'Evaluate forecast deficits',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Cash Forecasts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the cash forecast', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function evaluateDeficits(string $id): JsonResponse
    {
        $evaluation = $this->forecastService->evaluateDeficits($id);

        return response()->json($evaluation);
    }
}
