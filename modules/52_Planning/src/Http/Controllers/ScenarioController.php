<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Planning\Http\Controllers\Concerns\InteractsWithPlanning;
use Modules\Planning\Http\Requests\ScenarioCopyRequest;
use Modules\Planning\Http\Requests\ScenarioRequest;
use Modules\Planning\Http\Resources\ScenarioResource;
use Modules\Planning\Models\PlanningScenario;
use Modules\Planning\Services\ScenarioService;
use OpenApi\Attributes as OA;

/**
 * Planning scenarios: the baseline, what-if copies and the comparison between two scenarios.
 */
final class ScenarioController extends Controller
{
    use InteractsWithPlanning;

    public function __construct(private readonly ScenarioService $scenarios) {}

    #[OA\Get(path: '/api/v1/planning/scenarios', operationId: 'listPlanningScenarios', description: 'Planning scenarios, the newest first. Filters: status, scenario_type.', summary: 'List planning scenarios', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Planning'], responses: [
        new OA\Response(response: 200, description: 'Scenarios', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PlanningScenario'))])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PlanningScenario::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in(PlanningScenario::Statuses)], 'scenario_type' => ['nullable', Rule::in(PlanningScenario::Types)]]);

        return ScenarioResource::collection(
            PlanningScenario::query()
                ->withCount('lines')
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['scenario_type']), static fn ($query) => $query->where('scenario_type', $filters['scenario_type']))
                ->orderByDesc('created_at')
                ->orderBy('name')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/planning/scenarios', operationId: 'storePlanningScenario', description: 'Create a draft scenario. A company has one baseline: further scenarios are what-if scenarios (or copies of a scenario).', summary: 'Create a planning scenario', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlanningScenarioRequest')), tags: ['Planning'], responses: [
        new OA\Response(response: 201, description: 'Scenario created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningScenario')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function store(ScenarioRequest $request): JsonResponse
    {
        $this->authorize('create', PlanningScenario::class);

        return (new ScenarioResource($this->scenarios->create($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/planning/scenarios/compare', operationId: 'comparePlanningScenarios', description: 'Totals, gaps and capacity overload of two scenarios side by side, per item, with the delta of the second against the first.', summary: 'Compare two scenarios', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Planning'], parameters: [
        new OA\Parameter(name: 'base_id', description: 'The reference scenario', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'other_id', description: 'The scenario compared with the reference', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Comparison', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningComparison')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function compare(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PlanningScenario::class);
        $filters = $request->validate([
            'base_id' => ['required', 'uuid', Rule::exists('planning_scenarios', 'id')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')],
            'other_id' => ['required', 'uuid', 'different:base_id', Rule::exists('planning_scenarios', 'id')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')],
        ]);

        return response()->json(['data' => $this->scenarios->compare(
            PlanningScenario::query()->findOrFail($filters['base_id']),
            PlanningScenario::query()->findOrFail($filters['other_id']),
        )]);
    }

    #[OA\Get(path: '/api/v1/planning/scenarios/{id}', operationId: 'showPlanningScenario', description: 'Return a scenario with its approval trail.', summary: 'Show a planning scenario', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 200, description: 'Scenario', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningScenario')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
    ])]
    public function show(string $id): ScenarioResource
    {
        $scenario = PlanningScenario::query()->withCount('lines')->with('approvals')->findOrFail($id);
        $this->authorize('view', $scenario);

        return new ScenarioResource($scenario);
    }

    #[OA\Put(path: '/api/v1/planning/scenarios/{id}', operationId: 'updatePlanningScenario', description: 'Change a draft scenario. A scenario that is under review, approved or locked is not edited.', summary: 'Update a planning scenario', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlanningScenarioRequest')), tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 200, description: 'Scenario updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningScenario')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function update(ScenarioRequest $request, string $id): ScenarioResource
    {
        $scenario = PlanningScenario::query()->findOrFail($id);
        $this->authorize('update', $scenario);

        return new ScenarioResource($this->scenarios->update($scenario, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/planning/scenarios/{id}', operationId: 'deletePlanningScenario', description: 'Delete a draft scenario with its lines and constraints (soft delete). A scenario that was published is kept.', summary: 'Delete a planning scenario', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 204, description: 'Scenario deleted'),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $scenario = PlanningScenario::query()->findOrFail($id);
        $this->authorize('delete', $scenario);
        $this->scenarios->delete($scenario);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/planning/scenarios/{id}/copy', operationId: 'copyPlanningScenario', description: 'Create a what-if draft copy of a scenario of any status, with its lines and capacity constraints. The demand and the supply may be scaled by a percentage (10 adds 10 per cent, -20 removes 20 per cent).', summary: 'Copy a scenario as what-if', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlanningScenarioCopyRequest')), tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 201, description: 'Copy created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningScenario')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function copy(ScenarioCopyRequest $request, string $id): JsonResponse
    {
        $source = PlanningScenario::query()->findOrFail($id);
        $this->authorize('create', PlanningScenario::class);
        $this->authorize('view', $source);

        return (new ScenarioResource($this->scenarios->copy($source, $request->validated())->loadCount('lines')))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
