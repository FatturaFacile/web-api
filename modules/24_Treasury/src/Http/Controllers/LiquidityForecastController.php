<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Http\Requests\StoreForecastScenarioRequest;
use Modules\Treasury\Http\Requests\StoreRecurringCashItemRequest;
use Modules\Treasury\Http\Resources\ForecastScenarioResource;
use Modules\Treasury\Http\Resources\RecurringCashItemResource;
use Modules\Treasury\Models\ForecastScenario;
use Modules\Treasury\Models\RecurringCashItem;
use Modules\Treasury\Services\LiquidityForecastService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Liquidity forecast from open receivables, open payables and recurring items, with scenarios; management of the
 * scenarios and of the recurring items.
 */
final class LiquidityForecastController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly LiquidityForecastService $forecasts) {}

    /**
     * The daily liquidity forecast of one currency for each scenario.
     */
    #[OA\Get(
        path: '/api/v1/treasury/liquidity/forecast',
        operationId: 'getTreasuryLiquidityForecast',
        description: 'Liquidity forecast of one currency (default: the accounting currency) over the horizon: opening cash of bank accounts and cash registers, open customer invoices on their due date (overdue on the first day), open supplier invoices and the recurring items. Each requested scenario shifts collections, accelerates payments and scales inflows; the result gives the daily balance, the lowest balance and the first day below minimum_balance.',
        summary: 'Liquidity forecast',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Liquidity'],
        parameters: [
            new OA\Parameter(name: 'horizon_days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 90, maximum: 730, minimum: 1)),
            new OA\Parameter(name: 'scenarios[]', description: 'Scenario codes; default: the baseline only', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string'))),
            new OA\Parameter(name: 'currency', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 3, minLength: 3)),
            new OA\Parameter(name: 'minimum_balance', in: 'query', required: false, schema: new OA\Schema(type: 'number')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Forecast', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryLiquidityForecastEnvelope')),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function forecast(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validate([
            'horizon_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'scenarios' => ['nullable', 'array', 'max:5'],
            'scenarios.*' => ['string', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'minimum_balance' => ['nullable', 'numeric', 'decimal:0,2'],
        ]);

        return $this->successResponse($this->forecasts->forecast(
            (string) $tenantContext->getTenantId(),
            (int) ($data['horizon_days'] ?? 90),
            array_values($data['scenarios'] ?? []),
            $data['currency'] ?? null,
            isset($data['minimum_balance']) ? (string) $data['minimum_balance'] : null,
            CarbonImmutable::today(),
        ), 'Liquidity forecast');
    }

    /**
     * The forecast scenarios of the tenant.
     */
    #[OA\Get(
        path: '/api/v1/treasury/forecast-scenarios',
        operationId: 'listTreasuryForecastScenarios',
        description: 'Lists the liquidity forecast scenarios of the active tenant.',
        summary: 'List forecast scenarios',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Liquidity'],
        responses: [
            new OA\Response(response: 200, description: 'Scenarios', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryForecastScenarioResource')),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
        ]
    )]
    public function scenarios(): JsonResponse
    {
        return $this->successResponse(ForecastScenarioResource::collection(ForecastScenario::query()->orderBy('code')->get()), 'Forecast scenarios');
    }

    /**
     * Creates a scenario.
     */
    #[OA\Post(
        path: '/api/v1/treasury/forecast-scenarios',
        operationId: 'createTreasuryForecastScenario',
        description: 'Creates a liquidity forecast scenario with its collection delay, payment acceleration and inflow factor.',
        summary: 'Create forecast scenario',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TreasuryStoreForecastScenarioRequest')),
        tags: ['Treasury Liquidity'],
        responses: [
            new OA\Response(response: 201, description: 'Scenario created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryForecastScenarioResource'),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function storeScenario(StoreForecastScenarioRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $scenario = ForecastScenario::query()->create(['tenant_id' => $tenantContext->getTenantId(), 'is_baseline' => false, 'is_active' => true] + $request->validated());

        return $this->successResponse(new ForecastScenarioResource($scenario->refresh()), 'Forecast scenario created', Response::HTTP_CREATED);
    }

    /**
     * The recurring cash items of the tenant.
     */
    #[OA\Get(
        path: '/api/v1/treasury/recurring-items',
        operationId: 'listTreasuryRecurringItems',
        description: 'Lists the recurring cash items (rent, instalments, salaries...) projected by the liquidity forecast.',
        summary: 'List recurring cash items',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Liquidity'],
        responses: [
            new OA\Response(response: 200, description: 'Recurring items', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryRecurringCashItemResource')),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
        ]
    )]
    public function recurringItems(): JsonResponse
    {
        return $this->successResponse(RecurringCashItemResource::collection(RecurringCashItem::query()->orderBy('next_date')->get()), 'Recurring cash items');
    }

    /**
     * Creates a recurring item.
     */
    #[OA\Post(
        path: '/api/v1/treasury/recurring-items',
        operationId: 'createTreasuryRecurringItem',
        description: 'Creates a recurring cash item projected by the liquidity forecast.',
        summary: 'Create recurring cash item',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TreasuryStoreRecurringCashItemRequest')),
        tags: ['Treasury Liquidity'],
        responses: [
            new OA\Response(response: 201, description: 'Recurring item created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryRecurringCashItemResource'),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function storeRecurringItem(StoreRecurringCashItemRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $item = RecurringCashItem::query()->create(['tenant_id' => $tenantContext->getTenantId(), 'company_id' => $tenantContext->getTenantId(), 'is_active' => true] + $request->validated() + ['category' => 'other']);

        return $this->successResponse(new RecurringCashItemResource($item->refresh()), 'Recurring cash item created', Response::HTTP_CREATED);
    }

    /**
     * Deletes a recurring item.
     */
    #[OA\Delete(
        path: '/api/v1/treasury/recurring-items/{id}',
        operationId: 'deleteTreasuryRecurringItem',
        description: 'Deletes a recurring cash item: the forecast no longer projects it.',
        summary: 'Delete recurring cash item',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Liquidity'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TreasuryRecurringItemId')],
        responses: [
            new OA\Response(response: 200, description: 'Recurring item deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryNotFound', response: 404),
        ]
    )]
    public function destroyRecurringItem(string $id): JsonResponse
    {
        RecurringCashItem::query()->findOrFail($id)->delete();

        return $this->successResponse(null, 'Recurring cash item deleted');
    }
}
