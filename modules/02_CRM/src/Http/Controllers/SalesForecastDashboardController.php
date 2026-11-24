<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\CRM\Http\Requests\SalesDashboardRequest;
use Modules\CRM\Services\SalesForecastDashboardService;
use OpenApi\Attributes as OA;

/**
 * Sales forecasting and quota tracking dashboard, and the loss analysis for retrospective BI.
 */
final class SalesForecastDashboardController extends Controller
{
    public function __construct(
        private readonly SalesForecastDashboardService $dashboard,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Monthly or quarterly forecast against the quotas of the sales reps.
     */
    #[OA\Get(
        path: '/api/v1/crm/forecasts/dashboard',
        operationId: 'getCrmSalesForecastDashboard',
        description: 'Aggregates deals into monthly or quarterly buckets by expected close date (actual close date once closed), split into Pipeline, Best Case and Commit alongside Closed Won and Closed Lost, with weighted value, and compares them with the quotas assigned per sales rep and period (quota tracking). The reps block covers the requested quarter, month or the whole year.',
        summary: 'Sales forecast and quota dashboard',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Forecasts'],
        parameters: [
            new OA\Parameter(name: 'granularity', in: 'query', required: false, description: 'month or quarter', schema: new OA\Schema(type: 'string', enum: ['month', 'quarter'])),
            new OA\Parameter(name: 'year', in: 'query', required: false, description: 'Year, defaults to the current one', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'quarter', in: 'query', required: false, description: 'Restricts the rep comparison to a quarter', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'month', in: 'query', required: false, description: 'Restricts the rep comparison to a month', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'pipeline_id', in: 'query', required: false, description: 'Pipeline', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'owner_user_id', in: 'query', required: false, description: 'Sales rep', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Forecast buckets, totals and rep comparison', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function forecast(SalesDashboardRequest $request): JsonResponse
    {
        return response()->json($this->dashboard->dashboard((string) $this->tenantContext->getTenantId(), $request->validated()));
    }

    /**
     * Loss analysis by reason and winning competitor.
     */
    #[OA\Get(
        path: '/api/v1/crm/reports/loss-analysis',
        operationId: 'getCrmLossAnalysisReport',
        description: 'Breakdown of the lost deals by structured loss reason and by winning competitor (count and value), for retrospective business intelligence. Filters: pipeline_id, from/to on the close date.',
        summary: 'Loss analysis report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Reports'],
        parameters: [
            new OA\Parameter(name: 'pipeline_id', in: 'query', required: false, description: 'Pipeline', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'from', in: 'query', required: false, description: 'Earliest close date', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, description: 'Latest close date', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loss analysis', content: new OA\JsonContent(type: 'object')),
        ]
    )]
    public function lossAnalysis(SalesDashboardRequest $request): JsonResponse
    {
        return response()->json($this->dashboard->lossAnalysis((string) $this->tenantContext->getTenantId(), $request->validated()));
    }
}
