<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\TalentLearning\Http\Controllers\Concerns\InteractsWithTalent;
use Modules\TalentLearning\Http\Requests\BudgetPoolRequest;
use Modules\TalentLearning\Http\Resources\BudgetPoolResource;
use Modules\TalentLearning\Models\CompBudgetPool;
use Modules\TalentLearning\Models\CompCycle;
use Modules\TalentLearning\Services\CompensationService;
use OpenApi\Attributes as OA;

/**
 * Budget pools of a compensation cycle.
 */
final class BudgetPoolController extends Controller
{
    use InteractsWithTalent;

    public function __construct(private readonly CompensationService $compensation) {}

    #[OA\Get(path: '/api/v1/talent-learning/compensation/pools', operationId: 'listTalentCompBudgetPools', description: 'Budget pools by name. Filter: cycle_id.', summary: 'List budget pools', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], responses: [
        new OA\Response(response: 200, description: 'Pools', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentBudgetPool'))])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CompBudgetPool::class);
        $filters = $request->validate(['cycle_id' => ['nullable', 'uuid']]);

        return BudgetPoolResource::collection(
            CompBudgetPool::query()->where('tenant_id', $this->tenantId())
                ->when(isset($filters['cycle_id']), static fn ($query) => $query->where('cycle_id', $filters['cycle_id']))
                ->orderBy('name')->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/talent-learning/compensation/pools', operationId: 'storeTalentCompBudgetPool', description: 'Add a budget pool to a cycle that is not closed. Without a department the pool is open to every employee.', summary: 'Create a budget pool', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentBudgetPoolRequest')), tags: ['Talent and learning'], responses: [
        new OA\Response(response: 201, description: 'Pool created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentBudgetPool')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function store(BudgetPoolRequest $request): JsonResponse
    {
        $this->authorize('create', CompBudgetPool::class);
        $data = $request->validated();
        $cycle = CompCycle::query()->findOrFail($data['cycle_id']);

        return (new BudgetPoolResource($this->compensation->createPool($cycle, $data)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/talent-learning/compensation/pools/{id}', operationId: 'showTalentCompBudgetPool', description: 'Return a budget pool.', summary: 'Show a budget pool', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Pool', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentBudgetPool')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
    ])]
    public function show(string $id): BudgetPoolResource
    {
        $pool = CompBudgetPool::query()->findOrFail($id);
        $this->authorize('view', $pool);

        return new BudgetPoolResource($pool);
    }

    #[OA\Put(path: '/api/v1/talent-learning/compensation/pools/{id}', operationId: 'updateTalentCompBudgetPool', description: 'Change a pool. The budget cannot go below what submitted and approved proposals already use.', summary: 'Update a budget pool', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentBudgetPoolRequest')), tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Pool updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentBudgetPool')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function update(BudgetPoolRequest $request, string $id): BudgetPoolResource
    {
        $pool = CompBudgetPool::query()->findOrFail($id);
        $this->authorize('update', $pool);

        return new BudgetPoolResource($this->compensation->updatePool($pool, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/talent-learning/compensation/pools/{id}', operationId: 'deleteTalentCompBudgetPool', description: 'Delete a pool without proposals (soft delete).', summary: 'Delete a budget pool', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 204, description: 'Pool deleted'),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $pool = CompBudgetPool::query()->findOrFail($id);
        $this->authorize('delete', $pool);
        $this->compensation->deletePool($pool);

        return response()->noContent();
    }
}
