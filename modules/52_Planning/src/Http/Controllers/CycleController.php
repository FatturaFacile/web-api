<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Planning\Http\Controllers\Concerns\InteractsWithPlanning;
use Modules\Planning\Http\Requests\CycleDecisionRequest;
use Modules\Planning\Http\Resources\PublicationResource;
use Modules\Planning\Http\Resources\ScenarioResource;
use Modules\Planning\Models\PlanningPublication;
use Modules\Planning\Models\PlanningScenario;
use Modules\Planning\Services\CycleService;
use OpenApi\Attributes as OA;

/**
 * The S&OP cycle: submit for review, approve or reject, lock, and publish the approved plan.
 */
final class CycleController extends Controller
{
    use InteractsWithPlanning;

    public function __construct(private readonly CycleService $cycle) {}

    #[OA\Post(path: '/api/v1/planning/scenarios/{id}/submit', operationId: 'submitPlanningScenario', description: 'Send a draft scenario to the S&OP review. The scenario must have at least one line; from now on it is read-only.', summary: 'Submit a scenario for review', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/PlanningCycleRequest')), tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 200, description: 'Scenario under review', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningScenario')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function submit(CycleDecisionRequest $request, string $id): ScenarioResource
    {
        $scenario = PlanningScenario::query()->findOrFail($id);
        $this->authorize('update', $scenario);

        return $this->respond($this->cycle->submit($scenario, $this->userId(), $request->validated()['comment'] ?? null));
    }

    #[OA\Post(path: '/api/v1/planning/scenarios/{id}/approve', operationId: 'approvePlanningScenario', description: 'Approve a scenario under review. A plan that overloads a resource is refused unless accept_overload is set together with a comment. Reserved to owners, administrators and members granted planning.approve.', summary: 'Approve a scenario', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/PlanningCycleRequest')), tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 200, description: 'Scenario approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningScenario')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function approve(CycleDecisionRequest $request, string $id): ScenarioResource
    {
        $scenario = PlanningScenario::query()->findOrFail($id);
        $this->authorize('approve', $scenario);
        $data = $request->validated();

        return $this->respond($this->cycle->approve($scenario, $this->userId(), $data['comment'] ?? null, (bool) ($data['accept_overload'] ?? false)));
    }

    #[OA\Post(path: '/api/v1/planning/scenarios/{id}/reject', operationId: 'rejectPlanningScenario', description: 'Send a scenario under review, or approved but not yet locked or published, back to draft. The comment with the reason is required. Reserved to approvers.', summary: 'Reject a scenario', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlanningCycleRequest')), tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 200, description: 'Scenario back to draft', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningScenario')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function reject(CycleDecisionRequest $request, string $id): ScenarioResource
    {
        $scenario = PlanningScenario::query()->findOrFail($id);
        $this->authorize('approve', $scenario);

        return $this->respond($this->cycle->reject($scenario, $this->userId(), $request->validated()['comment'] ?? null));
    }

    #[OA\Post(path: '/api/v1/planning/scenarios/{id}/lock', operationId: 'lockPlanningScenario', description: 'Lock an approved scenario: it becomes immutable. Reserved to approvers.', summary: 'Lock a scenario', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/PlanningCycleRequest')), tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 200, description: 'Scenario locked', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningScenario')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function lock(CycleDecisionRequest $request, string $id): ScenarioResource
    {
        $scenario = PlanningScenario::query()->findOrFail($id);
        $this->authorize('approve', $scenario);

        return $this->respond($this->cycle->lock($scenario, $this->userId(), $request->validated()['comment'] ?? null));
    }

    #[OA\Post(path: '/api/v1/planning/scenarios/{id}/publish', operationId: 'publishPlanningScenario', description: 'Publish an approved or locked scenario as the plan of the company: a new numbered version with a frozen snapshot of totals, gaps and capacity load. The previous publication stops being current. Reserved to approvers.', summary: 'Publish the approved plan', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/PlanningCycleRequest')), tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 201, description: 'Plan published', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningPublication')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function publish(CycleDecisionRequest $request, string $id): JsonResponse
    {
        $scenario = PlanningScenario::query()->findOrFail($id);
        $this->authorize('approve', $scenario);

        return (new PublicationResource($this->cycle->publish($scenario, $this->userId(), $request->validated()['comment'] ?? null)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/planning/publications', operationId: 'listPlanningPublications', description: 'Published plan versions of the company, the latest first. Filter: scenario_id.', summary: 'List published plans', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Planning'], parameters: [new OA\Parameter(name: 'scenario_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Publications', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PlanningPublication'))])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
    ])]
    public function publications(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PlanningPublication::class);
        $filters = request()->validate(['scenario_id' => ['nullable', 'uuid']]);

        return PublicationResource::collection(
            PlanningPublication::query()
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['scenario_id']), static fn ($query) => $query->where('scenario_id', $filters['scenario_id']))
                ->orderByDesc('version')
                ->paginate($this->perPage()),
        );
    }

    private function respond(PlanningScenario $scenario): ScenarioResource
    {
        return new ScenarioResource($scenario->loadCount('lines')->load('approvals'));
    }
}
