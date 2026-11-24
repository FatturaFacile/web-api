<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\AgentCommissions\Http\Controllers\Concerns\ResolvesAgentCommissionContext;
use Modules\AgentCommissions\Http\Requests\SaveAgentProfileRequest;
use Modules\AgentCommissions\Http\Resources\AgentProfileResource;
use Modules\AgentCommissions\Models\AgentProfile;
use Modules\AgentCommissions\Services\AgentRegistryService;
use OpenApi\Attributes as OA;

/**
 * Agent registry: contract conditions of the agents (type, regime, accrual basis, sub-agent split) on top of the
 * sales representatives of the invoicing module.
 */
final class AgentController extends Controller
{
    use ApiResponse;
    use ResolvesAgentCommissionContext;

    public function __construct(private readonly AgentRegistryService $registry) {}

    #[OA\Get(path: '/api/v1/agent-commissions/agents', operationId: 'agentCommissionsListAgents', summary: 'List agent profiles and their contract conditions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 200, description: 'Agent profiles')])]
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', AgentProfile::class);

        return $this->successResponse(AgentProfileResource::collection($this->registry->agents($request->only(['agent_type', 'is_active']))));
    }

    #[OA\Get(path: '/api/v1/agent-commissions/representatives/available', operationId: 'agentCommissionsAvailableRepresentatives', summary: 'Sales representatives that have no agent profile yet', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 200, description: 'Sales representatives')])]
    public function representatives(): JsonResponse
    {
        Gate::authorize('viewAny', AgentProfile::class);

        return $this->successResponse($this->registry->availableRepresentatives()->map(static fn ($rep): array => [
            'id' => $rep->id,
            'rep_code' => $rep->rep_code,
            'name' => $rep->name,
            'email' => $rep->email,
            'default_commission_rate' => (string) $rep->default_commission_rate,
        ])->all());
    }

    #[OA\Post(path: '/api/v1/agent-commissions/agents', operationId: 'agentCommissionsCreateAgent', summary: 'Create the agent profile of a sales representative', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 201, description: 'Agent profile created')])]
    public function store(SaveAgentProfileRequest $request): JsonResponse
    {
        $profile = $this->registry->createAgent($request->validated());

        return $this->successResponse(new AgentProfileResource($profile), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/agent-commissions/agents/{id}', operationId: 'agentCommissionsShowAgent', summary: 'Show an agent profile', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Agent profile')])]
    public function show(string $id): JsonResponse
    {
        Gate::authorize('viewAny', AgentProfile::class);

        return $this->successResponse(new AgentProfileResource(AgentProfile::query()->with(['representative', 'parent.representative'])->findOrFail($id)));
    }

    #[OA\Put(path: '/api/v1/agent-commissions/agents/{id}', operationId: 'agentCommissionsUpdateAgent', summary: 'Update the contract conditions of an agent', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Agent profile updated')])]
    public function update(SaveAgentProfileRequest $request, string $id): JsonResponse
    {
        $profile = $this->registry->updateAgent(AgentProfile::query()->findOrFail($id), $request->validated());

        return $this->successResponse(new AgentProfileResource($profile), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/agent-commissions/agents/{id}', operationId: 'agentCommissionsDeleteAgent', summary: 'Delete an agent profile', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Agent profile deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $profile = AgentProfile::query()->findOrFail($id);
        Gate::authorize('manage', $profile);
        $this->registry->deleteAgent($profile);

        return $this->successResponse(null, $this->message('deleted'));
    }
}
