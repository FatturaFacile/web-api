<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\AgentCommissions\Application\Queries\ResolveRuleHandler;
use Modules\AgentCommissions\Application\Queries\ResolveRuleQuery;
use Modules\AgentCommissions\Http\Controllers\Concerns\ResolvesAgentCommissionContext;
use Modules\AgentCommissions\Http\Requests\ResolveRuleRequest;
use Modules\AgentCommissions\Http\Requests\SaveCommissionRuleRequest;
use Modules\AgentCommissions\Http\Resources\CommissionRuleResource;
use Modules\AgentCommissions\Models\CommissionRule;
use Modules\AgentCommissions\Services\AgentRegistryService;
use OpenApi\Attributes as OA;

/**
 * Commission rules by product category, customer and zone, with priority and optional progressive tiers.
 */
final class CommissionRuleController extends Controller
{
    use ApiResponse;
    use ResolvesAgentCommissionContext;

    public function __construct(
        private readonly AgentRegistryService $registry,
        private readonly ResolveRuleHandler $resolver,
    ) {}

    #[OA\Get(path: '/api/v1/agent-commissions/rules', operationId: 'agentCommissionsListRules', summary: 'List commission rules', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 200, description: 'Commission rules')])]
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CommissionRule::class);

        return $this->successResponse(CommissionRuleResource::collection($this->registry->rules($request->only(['agent_profile_id']))));
    }

    #[OA\Post(path: '/api/v1/agent-commissions/rules', operationId: 'agentCommissionsCreateRule', summary: 'Create a commission rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 201, description: 'Rule created')])]
    public function store(SaveCommissionRuleRequest $request): JsonResponse
    {
        return $this->successResponse(new CommissionRuleResource($this->registry->createRule($request->validated())), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/agent-commissions/rules/{id}', operationId: 'agentCommissionsUpdateRule', summary: 'Update a commission rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Rule updated')])]
    public function update(SaveCommissionRuleRequest $request, string $id): JsonResponse
    {
        $rule = $this->registry->updateRule(CommissionRule::query()->with('tiers')->findOrFail($id), $request->validated());

        return $this->successResponse(new CommissionRuleResource($rule), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/agent-commissions/rules/{id}', operationId: 'agentCommissionsDeleteRule', summary: 'Delete a commission rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Rule deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $rule = CommissionRule::query()->findOrFail($id);
        Gate::authorize('manage', $rule);
        $this->registry->deleteRule($rule);

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Get(path: '/api/v1/agent-commissions/rules/resolve', operationId: 'agentCommissionsResolveRule', summary: 'Simulate which rule wins for an agent, customer and product category', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 200, description: 'Winning rule or fallback rate')])]
    public function resolve(ResolveRuleRequest $request): JsonResponse
    {
        $result = $this->resolver->handle(new ResolveRuleQuery(
            (string) $request->validated('agent_profile_id'),
            $request->validated('customer_id'),
            $request->validated('product_category_id'),
            (string) ($request->validated('date') ?? CarbonImmutable::today()->toDateString()),
        ));

        return $this->successResponse([
            'rule' => $result['rule'] === null ? null : new CommissionRuleResource($result['rule']->loadMissing('tiers')),
            'fallback_rate' => $result['fallback_rate'],
            'zone_ids' => $result['zone_ids'],
        ]);
    }
}
