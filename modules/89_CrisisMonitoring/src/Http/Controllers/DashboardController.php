<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\CrisisMonitoring\Application\Commands\ComputeRollingDscrAndCrisisIndicatorsCommand;
use Modules\CrisisMonitoring\Application\Handlers\CalculateRequiredRangruecktrittAmountHandler;
use Modules\CrisisMonitoring\Application\Handlers\ComputeRollingDscrAndCrisisIndicatorsHandler;
use Modules\CrisisMonitoring\Application\Handlers\GetCncPracticalTestHandler;
use Modules\CrisisMonitoring\Application\Handlers\GetCrisisDashboardOverviewHandler;
use Modules\CrisisMonitoring\Application\Handlers\GetDscrProjectionTimelineHandler;
use Modules\CrisisMonitoring\Application\Queries\CalculateRequiredRangruecktrittAmountQuery;
use Modules\CrisisMonitoring\Application\Queries\GetCncPracticalTestQuery;
use Modules\CrisisMonitoring\Application\Queries\GetCrisisDashboardOverviewQuery;
use Modules\CrisisMonitoring\Application\Queries\GetDscrProjectionTimelineQuery;
use Modules\CrisisMonitoring\Application\Services\CrisisTexts;
use Modules\CrisisMonitoring\Http\Controllers\Concerns\ResolvesCrisisContext;
use Modules\CrisisMonitoring\Http\Requests\CrisisQueryRequest;
use Modules\CrisisMonitoring\Support\AtecoSection;
use Modules\LiquidityPlanning\Models\LiquiditySettings;
use OpenApi\Attributes as OA;

/**
 * The crisis dashboard (Italian CCII and Swiss Art. 725 CO), the DSCR timeline, the CNC practical test, the Rangrücktritt calculator and
 * the reference metadata. The controller only orchestrates: the handlers compute.
 */
final class DashboardController extends Controller
{
    use ApiResponse;
    use ResolvesCrisisContext;

    #[OA\Get(path: '/api/v1/crisis-monitoring/dashboard', operationId: 'getCrisisMonitoringDashboard', summary: 'Unified crisis dashboard (IT CCII and CH Art. 725 CO)', description: 'Computed live from the ledgers: rolling DSCR (6 and 12 months, two approaches), the seven early-warning indicators, the Art. 25-novies monitor, the CNC practical test and the Art. 725, 725a and 725b CO guardrails.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Crisis Monitoring'], responses: [new OA\Response(response: 200, description: 'The dashboard')])]
    public function dashboard(CrisisQueryRequest $request, GetCrisisDashboardOverviewHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->handle(new GetCrisisDashboardOverviewQuery($this->tenant(), $request->asOf())), CrisisTexts::get('crisisApi.message.dashboard', CrisisTexts::locale()));
    }

    #[OA\Post(path: '/api/v1/crisis-monitoring/compute', operationId: 'computeCrisisMonitoringSnapshot', summary: 'Compute and store the snapshot of the day', description: 'Stores the monthly DSCR rows and the CCII and Art. 725 CO assessments, replacing the ones of the same day, and returns the dashboard.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Crisis Monitoring'], responses: [new OA\Response(response: 200, description: 'The stored dashboard')])]
    public function compute(CrisisQueryRequest $request, ComputeRollingDscrAndCrisisIndicatorsHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->handle(new ComputeRollingDscrAndCrisisIndicatorsCommand($this->tenant(), $request->asOf(), 'manual')), CrisisTexts::get('crisisApi.message.computed', CrisisTexts::locale()));
    }

    #[OA\Get(path: '/api/v1/crisis-monitoring/timeline', operationId: 'getCrisisMonitoringTimeline', summary: 'Monthly DSCR timeline', description: 'Horizon 6 or 12 months; approach treasury (1) or operating (2).', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Crisis Monitoring'], responses: [new OA\Response(response: 200, description: 'The timeline')])]
    public function timeline(CrisisQueryRequest $request, GetDscrProjectionTimelineHandler $handler): JsonResponse
    {
        $query = new GetDscrProjectionTimelineQuery($this->tenant(), (int) $request->validated('horizon', 12), (string) $request->validated('approach', 'treasury'), $request->asOf());

        return $this->successResponse($handler->handle($query), CrisisTexts::get('crisisApi.message.timeline', CrisisTexts::locale()));
    }

    #[OA\Get(path: '/api/v1/crisis-monitoring/cnc-practical-test', operationId: 'getCrisisMonitoringCncPracticalTest', summary: 'CNC practical test (D.M. 21 March 2023)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Crisis Monitoring'], responses: [new OA\Response(response: 200, description: 'The test result and checklist')])]
    public function cnc(CrisisQueryRequest $request, GetCncPracticalTestHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->handle(new GetCncPracticalTestQuery($this->tenant(), $request->asOf())), CrisisTexts::get('crisisApi.message.cnc', CrisisTexts::locale()));
    }

    #[OA\Get(path: '/api/v1/crisis-monitoring/rangruecktritt/required', operationId: 'getCrisisMonitoringRequiredRangruecktritt', summary: 'Required subordination amount (Art. 725b CO)', description: 'The claim still to be subordinated to cure the over-indebtedness, with accrued and future interest over the horizon, and the principal of a new agreement at the given rate.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Crisis Monitoring'], responses: [new OA\Response(response: 200, description: 'The required amounts')])]
    public function required(CrisisQueryRequest $request, CalculateRequiredRangruecktrittAmountHandler $handler): JsonResponse
    {
        $rate = $request->validated('interest_rate');
        $query = new CalculateRequiredRangruecktrittAmountQuery(
            $this->tenant(),
            (int) $request->validated('horizon_months', 12),
            $rate === null ? null : (string) $rate,
            $request->validated('basis'),
            $request->asOf(),
        );

        return $this->successResponse($handler->handle($query), CrisisTexts::get('crisisApi.message.rangruecktritt', CrisisTexts::locale()));
    }

    #[OA\Get(path: '/api/v1/crisis-monitoring/metadata', operationId: 'getCrisisMonitoringMetadata', summary: 'Reference data of the crisis monitoring forms', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Crisis Monitoring'], responses: [new OA\Response(response: 200, description: 'ATECO sections, legal forms, jurisdictions and DSCR bands')])]
    public function metadata(): JsonResponse
    {
        $tenant = $this->tenant();
        $settings = LiquiditySettings::current((string) $tenant->country);

        return $this->successResponse([
            'ateco_sections' => array_map(static fn (string $section): array => ['section' => $section, 'label_key' => 'crisisApi.ateco.'.$section], AtecoSection::Sections),
            'legal_forms' => array_map(static fn (string $code): array => ['code' => $code, 'label_key' => 'crisisApi.legal_form.'.$code], ['corporation', 'partnership', 'sole_proprietorship']),
            'jurisdictions' => array_map(static fn (string $code): array => ['code' => $code, 'label_key' => 'crisisApi.jurisdiction.'.$code], ['IT', 'CH']),
            'dscr_bands' => ['red_below' => (string) $settings->dscr_critical_threshold, 'yellow_below' => (string) $settings->dscr_warning_threshold],
        ], CrisisTexts::get('crisisApi.message.metadata', CrisisTexts::locale()));
    }
}
