<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\AgentCommissions\Http\Controllers\Concerns\ResolvesAgentCommissionContext;
use Modules\AgentCommissions\Http\Requests\SaveFiscalOverrideRequest;
use Modules\AgentCommissions\Http\Resources\FiscalParameterResource;
use Modules\AgentCommissions\Models\FiscalParameter;
use Modules\AgentCommissions\Services\AgentRegistryService;
use OpenApi\Attributes as OA;

/**
 * Enasarco / FIRR / withholding / VAT parameters: the seeded statutory defaults and the overrides of the tenant.
 */
final class FiscalParameterController extends Controller
{
    use ApiResponse;
    use ResolvesAgentCommissionContext;

    public function __construct(private readonly AgentRegistryService $registry) {}

    #[OA\Get(path: '/api/v1/agent-commissions/fiscal-parameters', operationId: 'agentCommissionsListFiscalParameters', summary: 'List the Enasarco, FIRR, withholding and VAT parameters', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 200, description: 'Fiscal parameters')])]
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', FiscalParameter::class);

        return $this->successResponse(FiscalParameterResource::collection($this->registry->fiscalParameters()));
    }

    #[OA\Put(path: '/api/v1/agent-commissions/fiscal-parameters', operationId: 'agentCommissionsSaveFiscalParameter', summary: 'Save a tenant override of a fiscal parameter', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 200, description: 'Override saved')])]
    public function store(SaveFiscalOverrideRequest $request): JsonResponse
    {
        return $this->successResponse(new FiscalParameterResource($this->registry->saveFiscalOverride($this->tenantId(), $request->validated())), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/agent-commissions/fiscal-parameters/{id}', operationId: 'agentCommissionsDeleteFiscalParameter', summary: 'Remove a tenant override and fall back to the statutory default', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Override removed')])]
    public function destroy(string $id): JsonResponse
    {
        Gate::authorize('settle', FiscalParameter::class);
        $parameter = FiscalParameter::query()->whereNotNull('tenant_id')->findOrFail($id);
        $this->registry->deleteFiscalOverride($parameter);

        return $this->successResponse(null, $this->message('deleted'));
    }
}
