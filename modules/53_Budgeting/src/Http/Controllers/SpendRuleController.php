<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Budgeting\Http\Controllers\Concerns\InteractsWithBudgeting;
use Modules\Budgeting\Http\Requests\SpendRuleRequest;
use Modules\Budgeting\Http\Resources\SpendRuleResource;
use Modules\Budgeting\Models\BudgetSpendRule;
use Modules\Budgeting\Services\SpendRuleService;
use OpenApi\Attributes as OA;

/**
 * The spend rules: allow, warn or block per company, account, cost centre or project. Every member reads; owners,
 * administrators, accountants and members granted budgeting.control.manage change them.
 */
final class SpendRuleController extends Controller
{
    use InteractsWithBudgeting;

    public function __construct(private readonly SpendRuleService $rules) {}

    #[OA\Get(path: '/api/v1/budgeting/spend-control/rules', operationId: 'listBudgetSpendRules', description: 'The spend rules of the company, the highest priority first.', summary: 'List spend rules', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], responses: [
        new OA\Response(response: 200, description: 'Rules', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
    ])]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BudgetSpendRule::class);

        return SpendRuleResource::collection(BudgetSpendRule::query()->where('tenant_id', $this->tenantId())->orderByDesc('priority')->orderBy('name')->paginate($this->perPage()));
    }

    #[OA\Post(path: '/api/v1/budgeting/spend-control/rules', operationId: 'storeBudgetSpendRule', description: 'Create a spend rule. A rule without account, cost centre and project is the company default; the most specific rule wins.', summary: 'Create a spend rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
        new OA\Response(response: 201, description: 'Rule created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function store(SpendRuleRequest $request): JsonResponse
    {
        $this->authorize('manageControl', new BudgetSpendRule(['tenant_id' => $this->tenantId()]));

        return (new SpendRuleResource($this->rules->create($this->tenantId(), $request->validated(), $this->userId())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/budgeting/spend-control/rules/{id}', operationId: 'updateBudgetSpendRule', description: 'Change a spend rule.', summary: 'Update a spend rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
        new OA\Response(response: 200, description: 'Rule updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function update(SpendRuleRequest $request, string $id): SpendRuleResource
    {
        $rule = BudgetSpendRule::query()->findOrFail($id);
        $this->authorize('manageControl', $rule);

        return new SpendRuleResource($this->rules->update($rule, $request->validated(), $this->userId()));
    }

    #[OA\Delete(path: '/api/v1/budgeting/spend-control/rules/{id}', operationId: 'deleteBudgetSpendRule', description: 'Delete a spend rule (soft delete).', summary: 'Delete a spend rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 204, description: 'Rule deleted'),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $rule = BudgetSpendRule::query()->findOrFail($id);
        $this->authorize('manageControl', $rule);
        $this->rules->delete($rule, $this->userId());

        return response()->noContent();
    }
}
