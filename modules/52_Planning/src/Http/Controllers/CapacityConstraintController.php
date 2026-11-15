<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Planning\Http\Controllers\Concerns\InteractsWithPlanning;
use Modules\Planning\Http\Requests\CapacityConstraintRequest;
use Modules\Planning\Http\Resources\ConstraintResource;
use Modules\Planning\Models\PlanningCapacityConstraint;
use Modules\Planning\Models\PlanningScenario;
use Modules\Planning\Services\CapacityService;
use Modules\Planning\Services\ScenarioService;
use OpenApi\Attributes as OA;

/**
 * The capacity of a resource per month of a scenario.
 */
final class CapacityConstraintController extends Controller
{
    use InteractsWithPlanning;

    public function __construct(private readonly CapacityService $capacity, private readonly ScenarioService $scenarios) {}

    #[OA\Get(path: '/api/v1/planning/scenarios/{id}/constraints', operationId: 'listPlanningConstraints', description: 'Capacity constraints of a scenario by resource and month.', summary: 'List capacity constraints', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 200, description: 'Constraints', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PlanningConstraint'))])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
    ])]
    public function index(string $id): AnonymousResourceCollection
    {
        $scenario = PlanningScenario::query()->findOrFail($id);
        $this->authorize('view', $scenario);

        return ConstraintResource::collection(
            PlanningCapacityConstraint::query()->with('resource')->where('scenario_id', $scenario->id)->orderBy('period')->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/planning/scenarios/{id}/constraints', operationId: 'storePlanningConstraint', description: 'Set the capacity of a resource in a month of a draft scenario, overriding its default capacity. Saving the same resource and month again replaces the capacity.', summary: 'Save a capacity constraint', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlanningConstraintRequest')), tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 201, description: 'Constraint saved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningConstraint')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function store(CapacityConstraintRequest $request, string $id): JsonResponse
    {
        $scenario = PlanningScenario::query()->findOrFail($id);
        $this->authorize('update', $scenario);

        return (new ConstraintResource($this->capacity->saveConstraint($scenario, $request->validated())->load('resource')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Delete(path: '/api/v1/planning/constraints/{id}', operationId: 'deletePlanningConstraint', description: 'Delete a capacity constraint of a draft scenario: the default capacity of the resource applies again.', summary: 'Delete a capacity constraint', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 204, description: 'Constraint deleted'),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $constraint = PlanningCapacityConstraint::query()->findOrFail($id);
        $this->authorize('delete', $constraint);
        $this->scenarios->assertDraft(PlanningScenario::query()->findOrFail($constraint->scenario_id));
        $constraint->delete();

        return response()->noContent();
    }
}
