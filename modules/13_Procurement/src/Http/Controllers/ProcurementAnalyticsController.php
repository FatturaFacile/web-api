<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Procurement\Http\Controllers\Concerns\ProcurementControllerHelpers;
use Modules\Procurement\Http\Requests\ProcurementPeriodRequest;
use Modules\Procurement\Services\ProcurementAnalyticsService;
use OpenApi\Attributes as OA;

/**
 * Purchase analytics: spend by supplier, category or cost centre, open purchase orders and price variance.
 */
#[OA\Tag(name: 'Procurement Analytics', description: 'Spend, open orders and price variance')]
final class ProcurementAnalyticsController extends Controller
{
    use ProcurementControllerHelpers;

    public function __construct(private readonly ProcurementAnalyticsService $analytics) {}

    /**
     * Spend of the period by dimension.
     */
    #[OA\Get(
        path: '/api/v1/procurement/analytics/spend',
        operationId: 'procurementAnalyticsSpend',
        description: 'Net value of approved, sent, received and closed orders dated in the period, converted with the order exchange rate, grouped by supplier, supplier category or cost centre, largest first.',
        summary: 'Purchase spend',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Analytics'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/ProcDateFrom'),
            new OA\Parameter(ref: '#/components/parameters/ProcDateTo'),
            new OA\Parameter(name: 'dimension', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'supplier', enum: ['supplier', 'category', 'cost_center'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Spend', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'key', type: 'string', nullable: true),
                new OA\Property(property: 'label', type: 'string', nullable: true),
                new OA\Property(property: 'orders', type: 'integer'),
                new OA\Property(property: 'spend', type: 'string'),
            ]))])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function spend(ProcurementPeriodRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->analytics->spend($this->tenantId(), $request->from(), $request->to(), (string) ($request->validated('dimension') ?? 'supplier'))]);
    }

    /**
     * Open purchase orders.
     */
    #[OA\Get(
        path: '/api/v1/procurement/analytics/open-orders',
        operationId: 'procurementAnalyticsOpenOrders',
        description: 'Approved, sent and partially received orders with the value still to receive (order currency) and whether they are overdue.',
        summary: 'Open purchase orders',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Analytics'],
        responses: [
            new OA\Response(response: 200, description: 'Open orders', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
        ]
    )]
    public function openOrders(): JsonResponse
    {
        return response()->json(['data' => $this->analytics->openOrders($this->tenantId())]);
    }

    /**
     * Price variance against the supplier price lists.
     */
    #[OA\Get(
        path: '/api/v1/procurement/analytics/price-variance',
        operationId: 'procurementAnalyticsPriceVariance',
        description: 'Per catalogue entry, ordered value against the value at the price list valid on the order date, for committed orders of the period.',
        summary: 'Purchase price variance',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Analytics'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcDateFrom'), new OA\Parameter(ref: '#/components/parameters/ProcDateTo')],
        responses: [
            new OA\Response(response: 200, description: 'Price variance', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function priceVariance(ProcurementPeriodRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->analytics->priceVariance($this->tenantId(), $request->from(), $request->to())]);
    }
}
