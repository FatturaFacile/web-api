<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\SaveMachineCenterRequest;
use Modules\Manufacturing\Http\Requests\TelemetryPingRequest;
use Modules\Manufacturing\Http\Resources\MfgMachineCenterResource;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgOperation;
use Modules\Manufacturing\Services\ProductionEngineering\ProductionEngineeringService;
use OpenApi\Attributes as OA;

/**
 * Machines and equipment of the work centres, with fixed asset, maintenance and Industria 4.0 / Transizione 5.0
 * telemetry linkage.
 */
final class MachineCenterController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(private readonly ProductionEngineeringService $engineering) {}

    /**
     * Paginated machines, optionally of one work centre.
     */
    #[OA\Get(path: '/api/v1/manufacturing/machines', operationId: 'listMfgMachines', summary: 'List machines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'], responses: [new OA\Response(response: 200, description: 'Machines')])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MfgMachineCenter::query()->where('tenant_id', $this->tenantId());
        if ($request->filled('work_center_id')) {
            $query->where('work_center_id', (string) $request->query('work_center_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return MfgMachineCenterResource::collection($query->orderBy('machine_code')->gridPaginate($this->perPage($request)));
    }

    /**
     * Creates a machine.
     */
    #[OA\Post(path: '/api/v1/manufacturing/machines', operationId: 'storeMfgMachine', summary: 'Create machine', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'], responses: [new OA\Response(response: 201, description: 'Machine created'), new OA\Response(response: 422, description: 'Validation error')])]
    public function store(SaveMachineCenterRequest $request): JsonResponse
    {
        return (new MfgMachineCenterResource($this->engineering->saveMachine($this->tenantId(), null, $request->validated())))->response()->setStatusCode(201);
    }

    /**
     * One machine.
     */
    #[OA\Get(path: '/api/v1/manufacturing/machines/{id}', operationId: 'showMfgMachine', summary: 'Get machine', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'], parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')], responses: [new OA\Response(response: 200, description: 'Machine'), new OA\Response(response: 404, description: 'Not found')])]
    public function show(string $id): MfgMachineCenterResource
    {
        return new MfgMachineCenterResource($this->owned(MfgMachineCenter::class, $id));
    }

    /**
     * Updates a machine.
     */
    #[OA\Put(path: '/api/v1/manufacturing/machines/{id}', operationId: 'updateMfgMachine', summary: 'Update machine', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'], parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')], responses: [new OA\Response(response: 200, description: 'Machine updated'), new OA\Response(response: 404, description: 'Not found'), new OA\Response(response: 422, description: 'Validation error')])]
    public function update(SaveMachineCenterRequest $request, string $id): MfgMachineCenterResource
    {
        return new MfgMachineCenterResource($this->engineering->saveMachine($this->tenantId(), $this->owned(MfgMachineCenter::class, $id), $request->validated()));
    }

    /**
     * Deletes a machine that no routing operation prefers.
     */
    #[OA\Delete(path: '/api/v1/manufacturing/machines/{id}', operationId: 'deleteMfgMachine', summary: 'Delete machine', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'], parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')], responses: [new OA\Response(response: 204, description: 'Deleted'), new OA\Response(response: 404, description: 'Not found'), new OA\Response(response: 409, description: 'Used by a routing operation')])]
    public function destroy(string $id): JsonResponse
    {
        $machine = $this->owned(MfgMachineCenter::class, $id);
        if (MfgOperation::query()->where('tenant_id', $this->tenantId())->where('machine_center_id', $machine->id)->exists()) {
            return response()->json(['message' => 'The machine is used by a routing operation.', 'errors' => ['machine' => ['The machine is used by a routing operation.']]], 409);
        }
        $machine->delete();

        return response()->json(null, 204);
    }

    /**
     * Records a telemetry ping (and optionally the counters of a period) of an Industria 4.0 / Transizione 5.0 machine.
     */
    #[OA\Post(path: '/api/v1/manufacturing/machines/{id}/telemetry-ping', operationId: 'telemetryPingMfgMachine', summary: 'Machine telemetry ping', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'], parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')], responses: [new OA\Response(response: 200, description: 'Telemetry recorded'), new OA\Response(response: 404, description: 'Not found'), new OA\Response(response: 422, description: 'Validation error')])]
    public function telemetryPing(TelemetryPingRequest $request, string $id): MfgMachineCenterResource
    {
        return new MfgMachineCenterResource($this->engineering->telemetryPing($this->owned(MfgMachineCenter::class, $id), $request->validated()));
    }
}
