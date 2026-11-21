<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\AnalyticsDss\Application\Commands\ExportBoardPackCommand;
use Modules\AnalyticsDss\Application\Handlers\ExportBoardPackHandler;
use Modules\AnalyticsDss\Application\Handlers\GetDssDrillDownHandler;
use Modules\AnalyticsDss\Application\Handlers\GetExecutiveDssOverviewHandler;
use Modules\AnalyticsDss\Application\Handlers\GetProfitabilityMatrixHandler;
use Modules\AnalyticsDss\Application\Handlers\GetVarianceBridgeHandler;
use Modules\AnalyticsDss\Application\Handlers\SimulateStrategicScenarioHandler;
use Modules\AnalyticsDss\Application\Queries\GetDssDrillDownQuery;
use Modules\AnalyticsDss\Application\Queries\GetExecutiveDssOverviewQuery;
use Modules\AnalyticsDss\Application\Queries\GetProfitabilityMatrixQuery;
use Modules\AnalyticsDss\Application\Queries\GetVarianceBridgeQuery;
use Modules\AnalyticsDss\Application\Queries\SimulateStrategicScenarioQuery;
use Modules\AnalyticsDss\Presentation\Http\Requests\DrillDownRequest;
use Modules\AnalyticsDss\Presentation\Http\Requests\DssFilterRequest;
use Modules\AnalyticsDss\Presentation\Http\Requests\ExportBoardPackRequest;
use Modules\AnalyticsDss\Presentation\Http\Requests\ProfitabilityMatrixRequest;
use Modules\AnalyticsDss\Presentation\Http\Requests\SimulateScenarioRequest;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Executive Decision Support endpoints (Issue #214): scorecard, profitability matrix, variance bridge, what-if simulation,
 * drill-down and board pack. Thin: the FormRequest validates, the handler of the CQRS query or command answers.
 */
final class ExecutiveDssController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(path: '/api/v1/analytics/dss/overview', operationId: 'getExecutiveDssOverview', description: 'Balanced Scorecard KPIs (financial, customer, efficiency) with sparklines and comparison with the baseline, the cash conversion cycle and the active strategic alerts.', summary: 'Executive DSS overview', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Executive DSS'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/DssLegalEntityIds'), new OA\Parameter(ref: '#/components/parameters/DssCostCenterIds'), new OA\Parameter(ref: '#/components/parameters/DssCurrency'),
        new OA\Parameter(ref: '#/components/parameters/DssPeriodPreset'), new OA\Parameter(ref: '#/components/parameters/DssDateFrom'), new OA\Parameter(ref: '#/components/parameters/DssDateTo'),
        new OA\Parameter(ref: '#/components/parameters/DssComparisonBaseline'), new OA\Parameter(ref: '#/components/parameters/DssAsOf'),
    ], responses: [
        new OA\Response(response: 200, description: 'The overview: meta, scorecard {financial, customer, efficiency}, alerts, working_capital, operations', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 422, description: 'Invalid filter', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function overview(DssFilterRequest $request, GetExecutiveDssOverviewHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->handle(new GetExecutiveDssOverviewQuery($request->toFilter($this->tenantId()))));
    }

    #[OA\Get(path: '/api/v1/analytics/dss/profitability-matrix', operationId: 'getDssProfitabilityMatrix', description: 'Gross margin, contribution margin I and II, EBITDA and net operating profit per customer, product, channel, cost center or legal entity, with the Pareto 80/20 cut-off and the Whale Curve series.', summary: 'Profitability matrix and Whale Curve', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Executive DSS'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/DssLegalEntityIds'), new OA\Parameter(ref: '#/components/parameters/DssCostCenterIds'), new OA\Parameter(ref: '#/components/parameters/DssCurrency'),
        new OA\Parameter(ref: '#/components/parameters/DssPeriodPreset'), new OA\Parameter(ref: '#/components/parameters/DssDateFrom'), new OA\Parameter(ref: '#/components/parameters/DssDateTo'),
        new OA\Parameter(ref: '#/components/parameters/DssComparisonBaseline'), new OA\Parameter(ref: '#/components/parameters/DssAsOf'),
        new OA\Parameter(name: 'dimension', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['customer', 'product', 'channel', 'cost_center', 'legal_entity'], default: 'customer')),
        new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 500, default: 500)),
    ], responses: [
        new OA\Response(response: 200, description: 'rows (ranked), whale_curve, totals, pareto_cutoff_rank', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
    ])]
    public function profitabilityMatrix(ProfitabilityMatrixRequest $request, GetProfitabilityMatrixHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse($handler->handle(new GetProfitabilityMatrixQuery($request->toFilter($this->tenantId()), (string) ($data['dimension'] ?? 'customer'), (int) ($data['limit'] ?? 500))));
    }

    #[OA\Get(path: '/api/v1/analytics/dss/variance-bridge', operationId: 'getDssVarianceBridge', description: 'Waterfall from the baseline (prior period, prior year or budget) to the actual through volume, price, COGS, OPEX and FX translation effects, for EBITDA and revenue. The bridge always closes.', summary: 'EBITDA and revenue variance bridge', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Executive DSS'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/DssLegalEntityIds'), new OA\Parameter(ref: '#/components/parameters/DssCostCenterIds'), new OA\Parameter(ref: '#/components/parameters/DssCurrency'),
        new OA\Parameter(ref: '#/components/parameters/DssPeriodPreset'), new OA\Parameter(ref: '#/components/parameters/DssDateFrom'), new OA\Parameter(ref: '#/components/parameters/DssDateTo'),
        new OA\Parameter(ref: '#/components/parameters/DssComparisonBaseline'), new OA\Parameter(ref: '#/components/parameters/DssAsOf'),
    ], responses: [
        new OA\Response(response: 200, description: 'ebitda_bridge, revenue_bridge, summary, line_variances, volume_basis', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
    ])]
    public function varianceBridge(DssFilterRequest $request, GetVarianceBridgeHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->handle(new GetVarianceBridgeQuery($request->toFilter($this->tenantId()))));
    }

    #[OA\Post(path: '/api/v1/analytics/dss/simulate', operationId: 'simulateDssScenario', description: 'Deterministic what-if on the actuals of the period: price %, COGS %, OPEX %, DSO days, EUR/CHF shift % and FTE change, resulting in EBITDA, net cash flow and break-even. The response carries the inputs, so offline clients can reproduce it.', summary: 'Strategic what-if simulation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Executive DSS'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'currency', type: 'string', enum: ['CHF', 'EUR']),
        new OA\Property(property: 'period_preset', type: 'string', enum: ['MTD', 'QTD', 'YTD', 'L12M', 'CUSTOM']),
        new OA\Property(property: 'legal_entity_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
        new OA\Property(property: 'scenario', properties: [
            new OA\Property(property: 'price_pct', type: 'number'), new OA\Property(property: 'cogs_pct', type: 'number'), new OA\Property(property: 'opex_pct', type: 'number'),
            new OA\Property(property: 'dso_days_delta', type: 'number'), new OA\Property(property: 'fx_shift_pct', type: 'number'), new OA\Property(property: 'fte_delta', type: 'number'),
        ], type: 'object'),
    ], type: 'object')), responses: [
        new OA\Response(response: 200, description: 'inputs, scenario, baseline, simulated, delta', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 422, description: 'Invalid input', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function simulate(SimulateScenarioRequest $request, SimulateStrategicScenarioHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse($handler->handle(new SimulateStrategicScenarioQuery($request->toFilter($this->tenantId()), array_map('floatval', $data['scenario']))));
    }

    #[OA\Get(path: '/api/v1/analytics/dss/drill-down', operationId: 'getDssDrillDown', description: 'Paginated analytical records behind a KPI, bridge bar or matrix row, with deep links to the source ERP entities and to the report builder and KPI dashboards.', summary: 'Drill down to the underlying records', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Executive DSS'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/DssLegalEntityIds'), new OA\Parameter(ref: '#/components/parameters/DssCostCenterIds'), new OA\Parameter(ref: '#/components/parameters/DssCurrency'),
        new OA\Parameter(ref: '#/components/parameters/DssPeriodPreset'), new OA\Parameter(ref: '#/components/parameters/DssDateFrom'), new OA\Parameter(ref: '#/components/parameters/DssDateTo'),
        new OA\Parameter(ref: '#/components/parameters/DssComparisonBaseline'), new OA\Parameter(ref: '#/components/parameters/DssAsOf'),
        new OA\Parameter(name: 'metric', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['revenue', 'cogs', 'opex', 'depreciation', 'cash', 'receivable', 'payable', 'inventory'])),
        new OA\Parameter(name: 'entity_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['customer', 'cost_center', 'legal_entity'])),
        new OA\Parameter(name: 'entity_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200)),
    ], responses: [
        new OA\Response(response: 200, description: 'data, pagination, total_amount, links', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
    ])]
    public function drillDown(DrillDownRequest $request, GetDssDrillDownHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse($handler->handle(new GetDssDrillDownQuery(
            $request->toFilter($this->tenantId()), (string) $data['metric'], $data['entity_type'] ?? null, $data['entity_id'] ?? null, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 50),
        )));
    }

    #[OA\Post(path: '/api/v1/analytics/dss/export-board-pack', operationId: 'exportDssBoardPack', description: 'Generates the Executive Board Briefing Pack as a localized PDF or an XLSX workbook (scorecard, alerts, EBITDA bridge, profitability) and streams it.', summary: 'Export the board pack', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Executive DSS'], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'format', type: 'string', enum: ['pdf', 'xlsx'], default: 'pdf'),
        new OA\Property(property: 'locale', type: 'string', enum: ['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT']),
        new OA\Property(property: 'currency', type: 'string', enum: ['CHF', 'EUR']),
        new OA\Property(property: 'period_preset', type: 'string', enum: ['MTD', 'QTD', 'YTD', 'L12M', 'CUSTOM']),
        new OA\Property(property: 'comparison_baseline', type: 'string', enum: ['PRIOR_PERIOD', 'PRIOR_YEAR', 'BUDGET']),
    ], type: 'object')), responses: [
        new OA\Response(response: 200, description: 'The file (application/pdf or the XLSX media type)'),
    ])]
    public function exportBoardPack(ExportBoardPackRequest $request, ExportBoardPackHandler $handler): BinaryFileResponse
    {
        $data = $request->validated();
        $file = $handler->handle(new ExportBoardPackCommand($request->toFilter($this->tenantId()), (string) ($data['format'] ?? 'pdf'), (string) ($data['locale'] ?? $request->user()->locale ?? 'en')));

        return response()->download($file['path'], $file['filename'], ['Content-Type' => $file['content_type']])->deleteFileAfterSend(true);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }
}
