<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\BondedWarehouses\Application\Commands\ExecuteBondedStockDischargeCommand;
use Modules\BondedWarehouses\Application\Commands\RegisterBondedWarehouseInboundMrnCommand;
use Modules\BondedWarehouses\Application\Handlers\ExecuteBondedStockDischargeHandler;
use Modules\BondedWarehouses\Application\Handlers\RegisterBondedWarehouseInboundMrnHandler;
use Modules\BondedWarehouses\Application\Queries\RegistryQueries;
use Modules\BondedWarehouses\Http\Controllers\Concerns\InteractsWithBondedWarehouses;
use Modules\BondedWarehouses\Http\Requests\DischargeRequest;
use Modules\BondedWarehouses\Http\Requests\RegisterInboundMrnRequest;
use Modules\BondedWarehouses\Http\Resources\MrnLotResource;
use Modules\BondedWarehouses\Models\BondedMrnLot;
use OpenApi\Attributes as OA;

/**
 * Bonded stock ledger per MRN (CDU 7100 / OZL): inbound registration, drill-down to the discharges, discharge simulator and execution.
 */
final class MrnLotController extends Controller
{
    use ApiResponse;
    use InteractsWithBondedWarehouses;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/bonded-warehouses/mrn-lots', operationId: 'bondedWarehousesMrnLotsIndex', summary: 'Bonded stock lots per MRN (filter by MRN, TARIC, regime, origin, status)', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 200, description: 'MRN lots')])]
    public function index(RegistryQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', BondedMrnLot::class);

        return $this->paged($queries->mrnLots($this->filters(), $this->perPage()), MrnLotResource::class);
    }

    #[OA\Get(path: '/api/v1/bonded-warehouses/mrn-lots/{id}', operationId: 'bondedWarehousesMrnLotsShow', summary: 'One MRN lot with every discharge movement', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 200, description: 'MRN lot')])]
    public function show(string $id, RegistryQueries $queries): JsonResponse
    {
        $lot = $queries->mrnLot($id);
        Gate::authorize('view', $lot);

        return $this->successResponse(new MrnLotResource($lot), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/bonded-warehouses/mrn-lots', operationId: 'bondedWarehousesMrnLotsStore', summary: 'Register the entry of goods under an MRN and engage the CGU', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 201, description: 'MRN lot')])]
    public function store(RegisterInboundMrnRequest $request, RegisterBondedWarehouseInboundMrnHandler $handler): JsonResponse
    {
        Gate::authorize('create', BondedMrnLot::class);

        $lot = $handler->handle(RegisterBondedWarehouseInboundMrnCommand::fromArray($request->validated()));

        return $this->createdResponse(new MrnLotResource($lot->load('authorization:id,authorization_code,regime_type,currency')), $this->message('mrn_registered'));
    }

    #[OA\Post(path: '/api/v1/bonded-warehouses/mrn-lots/discharge/simulate', operationId: 'bondedWarehousesDischargeSimulate', summary: 'Simulate a FIFO or Specific MRN discharge (duties, VAT and CGU release) without writing', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 200, description: 'Discharge plan')])]
    public function simulate(DischargeRequest $request, ExecuteBondedStockDischargeHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', BondedMrnLot::class);

        return $this->successResponse($handler->handle(ExecuteBondedStockDischargeCommand::fromArray($request->validated(), $this->userId(), true)), $this->message('discharge_simulated'));
    }

    #[OA\Post(path: '/api/v1/bonded-warehouses/mrn-lots/discharge', operationId: 'bondedWarehousesDischargeExecute', summary: 'Discharge bonded stock (IM4, EX3, export, T1 or usual handling) and release the CGU share', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 201, description: 'Discharge executed with the customs summary')])]
    public function discharge(DischargeRequest $request, ExecuteBondedStockDischargeHandler $handler): JsonResponse
    {
        Gate::authorize('create', BondedMrnLot::class);

        return $this->createdResponse($handler->handle(ExecuteBondedStockDischargeCommand::fromArray($request->validated(), $this->userId())), $this->message('discharge_executed'));
    }
}
