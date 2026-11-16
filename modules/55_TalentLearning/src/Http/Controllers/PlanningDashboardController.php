<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\TalentLearning\Http\Controllers\Concerns\InteractsWithTalent;
use Modules\TalentLearning\Models\SuccessionPosition;
use Modules\TalentLearning\Services\CompensationService;
use Modules\TalentLearning\Services\SuccessionService;
use OpenApi\Attributes as OA;

/**
 * Summary of the succession plan and of the compensation planning.
 */
final class PlanningDashboardController extends Controller
{
    use InteractsWithTalent;

    public function __construct(private readonly SuccessionService $succession, private readonly CompensationService $compensation) {}

    #[OA\Get(path: '/api/v1/talent-learning/planning/dashboard', operationId: 'showTalentPlanningDashboard', description: 'Succession coverage (covered, at risk, uncovered positions), candidates by readiness, candidates at high risk of loss, and the budget, the commitments and the proposals of the current compensation cycle.', summary: 'Planning dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], responses: [
        new OA\Response(response: 200, description: 'Dashboard', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentPlanningDashboard')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
    ])]
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', SuccessionPosition::class);

        return response()->json(['data' => [
            'succession' => $this->succession->summary($this->tenantId()),
            'compensation' => $this->compensation->overview($this->tenantId()),
        ]]);
    }
}
