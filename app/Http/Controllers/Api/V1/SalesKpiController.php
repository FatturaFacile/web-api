<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SalesKpiRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Sales\SalesKpiService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Sales indicators of the active company: order backlog, fill rate, quote conversion and returns.
 */
class SalesKpiController extends Controller
{
    use ApiResponse;

    /**
     * Sales indicators for a period.
     */
    #[OA\Get(
        path: '/api/v1/sales/kpis',
        operationId: 'salesKpis',
        description: 'Sales indicators read from the documents: the current order backlog (open orders and the net value still to deliver), the fill rate of the orders dated in the period (lines delivered in full, share of the quantity delivered), the conversion of the quotes dated in the period (accepted over decided, converted into an order) and the returns opened in the period.',
        summary: 'Sales KPIs',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-01-01')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-03-31')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Indicators', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/SalesCycleKpis'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function __invoke(SalesKpiRequest $request, SalesKpiService $kpis): JsonResponse
    {
        return $this->successResponse($kpis->compute((string) $request->validated('from'), (string) $request->validated('to')), __('sales.messages.kpis'));
    }
}
