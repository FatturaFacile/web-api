<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\LiquidityPlanning\Http\Controllers\Concerns\ResolvesLiquidityContext;
use Modules\LiquidityPlanning\Http\Requests\OverviewRequest;
use Modules\LiquidityPlanning\Services\ForecastService;
use Modules\LiquidityPlanning\Services\LiquidityTexts;
use Modules\LiquidityPlanning\Services\ReportExporter;
use Modules\LiquidityPlanning\Services\ReportService;
use OpenApi\Attributes as OA;

/**
 * The rolling cash forecast with its what-if scenario and crisis indicators, and the board / bank report with its exports.
 */
final class OverviewController extends Controller
{
    use ApiResponse;
    use ResolvesLiquidityContext;

    public function __construct(
        private readonly ForecastService $forecast,
        private readonly ReportService $reports,
        private readonly ReportExporter $exporter,
    ) {}

    #[OA\Get(path: '/api/v1/liquidity-planning/overview', operationId: 'getLiquidityPlanningOverview', summary: 'Rolling cash forecast, what-if scenario and crisis indicators', description: 'Daily and weekly projection over 180 days in three reliability tiers (certain, probable, estimated), the 30/60/90/180 day summaries, DSCR, capital (CO 725 / Art. 2446 c.c.), PFN and liquidity indicators, for the baseline and for the scenario given by scenario_id or levers.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 200, description: 'The overview')])]
    public function overview(OverviewRequest $request): JsonResponse
    {
        return $this->successResponse($this->forecast->overview($this->tenant(), $request->levers(), $request->asOf(), $request->scenarioInfo()), LiquidityTexts::get('lpApi.message.overview', $request->locale()));
    }

    #[OA\Get(path: '/api/v1/liquidity-planning/report', operationId: 'getLiquidityPlanningReport', summary: 'Board and bank report (EBA aligned)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 200, description: 'The report')])]
    public function report(OverviewRequest $request): JsonResponse
    {
        return $this->successResponse($this->reports->build($this->tenant(), $request->levers(), $request->asOf(), $request->scenarioInfo()), LiquidityTexts::get('lpApi.message.report', $request->locale()));
    }

    #[OA\Get(path: '/api/v1/liquidity-planning/report/pdf', operationId: 'exportLiquidityPlanningReportPdf', summary: 'Board and bank report as a PDF', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 200, description: 'The PDF', content: new OA\MediaType(mediaType: 'application/pdf'))])]
    public function pdf(OverviewRequest $request): Response
    {
        $report = $this->reports->build($this->tenant(), $request->levers(), $request->asOf(), $request->scenarioInfo());

        return $this->download($this->exporter->pdf($report, $request->locale()), 'liquidity-report-'.$report['as_of'].'.pdf', 'application/pdf');
    }

    #[OA\Get(path: '/api/v1/liquidity-planning/export/xlsx', operationId: 'exportLiquidityPlanningXlsx', summary: 'Forecast and simulation data as an Excel workbook', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 200, description: 'The workbook', content: new OA\MediaType(mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))])]
    public function xlsx(OverviewRequest $request): Response
    {
        $report = $this->reports->build($this->tenant(), $request->levers(), $request->asOf(), $request->scenarioInfo());

        return $this->download($this->exporter->xlsx($report, $request->locale()), 'liquidity-forecast-'.$report['as_of'].'.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
