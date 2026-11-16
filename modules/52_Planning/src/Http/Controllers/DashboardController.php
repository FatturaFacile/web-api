<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Planning\Http\Controllers\Concerns\InteractsWithPlanning;
use Modules\Planning\Models\PlanningScenario;
use Modules\Planning\Services\PlanningDashboardService;
use OpenApi\Attributes as OA;

/**
 * Planning overview: scenarios per status, the current publication, the biggest gaps and the capacity load.
 */
final class DashboardController extends Controller
{
    use InteractsWithPlanning;

    public function __construct(private readonly PlanningDashboardService $dashboard) {}

    #[OA\Get(path: '/api/v1/planning/dashboard', operationId: 'showPlanningDashboard', description: 'Scenarios per status, scenarios waiting for approval, the current publication and, for the selected scenario (scenario_id, else the current publication, else the baseline, else the latest), the totals, the ten biggest gaps and the capacity load per resource and month.', summary: 'Planning dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Planning'], parameters: [new OA\Parameter(name: 'scenario_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Dashboard', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningDashboard')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PlanningScenario::class);
        $filters = $request->validate(['scenario_id' => ['nullable', 'uuid', Rule::exists('planning_scenarios', 'id')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')]]);

        return response()->json(['data' => $this->dashboard->summary($this->tenantId(), $filters['scenario_id'] ?? null)]);
    }
}
