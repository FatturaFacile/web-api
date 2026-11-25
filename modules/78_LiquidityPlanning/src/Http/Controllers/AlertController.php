<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\LiquidityPlanning\Http\Controllers\Concerns\ResolvesLiquidityContext;
use Modules\LiquidityPlanning\Http\Requests\AlertIndexRequest;
use Modules\LiquidityPlanning\Models\LiquidityAlert;
use Modules\LiquidityPlanning\Services\AlertService;
use Modules\LiquidityPlanning\Services\LiquidityTexts;
use OpenApi\Attributes as OA;

/**
 * The crisis early-warning alerts: history, evaluation on demand, acknowledgement and resolution.
 */
final class AlertController extends Controller
{
    use ApiResponse;
    use ResolvesLiquidityContext;

    public function __construct(private readonly AlertService $alerts) {}

    #[OA\Get(path: '/api/v1/liquidity-planning/alerts', operationId: 'listLiquidityPlanningAlerts', summary: 'Liquidity early-warning alerts', description: 'Filter by status (open, acknowledged, resolved); without a filter the whole history, newest first.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 200, description: 'The alerts')])]
    public function index(AlertIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $alerts = LiquidityAlert::query()
            ->when($validated['status'] ?? null, static fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('detected_at')
            ->limit(200)
            ->get();

        return $this->successResponse($alerts->map(static fn (LiquidityAlert $alert): array => AlertService::present($alert))->all(), LiquidityTexts::get('lpApi.message.alerts', LiquidityTexts::locale()));
    }

    #[OA\Post(path: '/api/v1/liquidity-planning/alerts/evaluate', operationId: 'evaluateLiquidityPlanningAlerts', summary: 'Evaluate the early-warning conditions now', description: 'Raises, refreshes and resolves the alerts of the baseline forecast and sends the notifications due (dashboard and email, within the cooldown).', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 200, description: 'The alerts open or acknowledged')])]
    public function evaluate(Request $request): JsonResponse
    {
        $alerts = $this->alerts->evaluate($this->tenant());

        return $this->successResponse(array_map(static fn (LiquidityAlert $alert): array => AlertService::present($alert), $alerts), LiquidityTexts::get('lpApi.message.alerts_evaluated', LiquidityTexts::locale()));
    }

    #[OA\Post(path: '/api/v1/liquidity-planning/alerts/{id}/acknowledge', operationId: 'acknowledgeLiquidityPlanningAlert', summary: 'Acknowledge an alert', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 200, description: 'The alert')])]
    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $alert = $this->alerts->acknowledge(LiquidityAlert::query()->findOrFail($id), $this->userId($request));

        return $this->successResponse(AlertService::present($alert), LiquidityTexts::get('lpApi.message.alert_acknowledged', LiquidityTexts::locale()));
    }

    #[OA\Post(path: '/api/v1/liquidity-planning/alerts/{id}/resolve', operationId: 'resolveLiquidityPlanningAlert', summary: 'Resolve an alert', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 200, description: 'The alert')])]
    public function resolve(Request $request, string $id): JsonResponse
    {
        $alert = $this->alerts->resolve(LiquidityAlert::query()->findOrFail($id));

        return $this->successResponse(AlertService::present($alert), LiquidityTexts::get('lpApi.message.alert_resolved', LiquidityTexts::locale()));
    }
}
