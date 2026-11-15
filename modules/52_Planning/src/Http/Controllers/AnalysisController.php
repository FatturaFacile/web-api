<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Planning\Http\Controllers\Concerns\InteractsWithPlanning;
use Modules\Planning\Models\PlanningScenario;
use Modules\Planning\Services\BalanceService;
use Modules\Planning\Services\CapacityService;
use OpenApi\Attributes as OA;

/**
 * Demand-supply balance with the gaps, and the finite capacity check of a scenario.
 */
final class AnalysisController extends Controller
{
    use InteractsWithPlanning;

    public function __construct(private readonly BalanceService $balance, private readonly CapacityService $capacity) {}

    #[OA\Get(path: '/api/v1/planning/scenarios/{id}/balance', operationId: 'showPlanningBalance', description: 'Demand, supply and gap (supply minus demand) per item and month of a scenario. A negative gap is a shortage. With carry_over (default) a surplus or shortage rolls into the following month as a running balance.', summary: 'Demand-supply balance', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId'), new OA\Parameter(name: 'carry_over', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', default: true))], responses: [
        new OA\Response(response: 200, description: 'Balance', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningBalance')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
    ])]
    public function balance(Request $request, string $id): JsonResponse
    {
        $scenario = PlanningScenario::query()->findOrFail($id);
        $this->authorize('view', $scenario);
        $filters = $request->validate(['carry_over' => ['nullable', 'boolean']]);

        return response()->json(['data' => $this->balance->balance($scenario, (bool) ($filters['carry_over'] ?? true))]);
    }

    #[OA\Get(path: '/api/v1/planning/scenarios/{id}/capacity', operationId: 'showPlanningCapacity', description: 'Finite capacity check: the supply plan loads every resource (quantity times the load per unit) and a month is overloaded when the load exceeds the capacity of the scenario (the constraint of the month, else the default capacity of the resource).', summary: 'Capacity check', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 200, description: 'Capacity load', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningCapacity')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
    ])]
    public function capacity(string $id): JsonResponse
    {
        $scenario = PlanningScenario::query()->findOrFail($id);
        $this->authorize('view', $scenario);

        return response()->json(['data' => $this->capacity->check($scenario)]);
    }
}
