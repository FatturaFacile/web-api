<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\AgentCommissions\Http\Controllers\Concerns\ResolvesAgentCommissionContext;
use Modules\AgentCommissions\Http\Requests\SaveZoneRequest;
use Modules\AgentCommissions\Http\Resources\ZoneResource;
use Modules\AgentCommissions\Models\Zone;
use Modules\AgentCommissions\Services\AgentRegistryService;
use OpenApi\Attributes as OA;

/**
 * Commission zones: a country, optionally narrowed to provinces / cantons.
 */
final class ZoneController extends Controller
{
    use ApiResponse;
    use ResolvesAgentCommissionContext;

    public function __construct(private readonly AgentRegistryService $registry) {}

    #[OA\Get(path: '/api/v1/agent-commissions/zones', operationId: 'agentCommissionsListZones', summary: 'List commission zones', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 200, description: 'Zones')])]
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Zone::class);

        return $this->successResponse(ZoneResource::collection($this->registry->zones()));
    }

    #[OA\Post(path: '/api/v1/agent-commissions/zones', operationId: 'agentCommissionsCreateZone', summary: 'Create a commission zone', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 201, description: 'Zone created')])]
    public function store(SaveZoneRequest $request): JsonResponse
    {
        return $this->successResponse(new ZoneResource($this->registry->saveZone(null, $request->validated())), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/agent-commissions/zones/{id}', operationId: 'agentCommissionsUpdateZone', summary: 'Update a commission zone', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Zone updated')])]
    public function update(SaveZoneRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new ZoneResource($this->registry->saveZone(Zone::query()->findOrFail($id), $request->validated())), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/agent-commissions/zones/{id}', operationId: 'agentCommissionsDeleteZone', summary: 'Delete a commission zone', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Zone deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $zone = Zone::query()->findOrFail($id);
        Gate::authorize('manage', $zone);
        $this->registry->deleteZone($zone);

        return $this->successResponse(null, $this->message('deleted'));
    }
}
