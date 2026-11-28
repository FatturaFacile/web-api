<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\OliveMillAndGrainMill\Application\Commands\AdjustTankStockCommand;
use Modules\OliveMillAndGrainMill\Application\Commands\PackageFromTankCommand;
use Modules\OliveMillAndGrainMill\Application\Commands\TransferBetweenTanksCommand;
use Modules\OliveMillAndGrainMill\Application\Handlers\SaveTankHandler;
use Modules\OliveMillAndGrainMill\Application\Handlers\TankOperationsHandler;
use Modules\OliveMillAndGrainMill\Application\Queries\MillQueries;
use Modules\OliveMillAndGrainMill\Http\Controllers\Concerns\InteractsWithOliveMill;
use Modules\OliveMillAndGrainMill\Http\Requests\AdjustTankRequest;
use Modules\OliveMillAndGrainMill\Http\Requests\PackageTankRequest;
use Modules\OliveMillAndGrainMill\Http\Requests\SaveTankRequest;
use Modules\OliveMillAndGrainMill\Http\Requests\TransferTanksRequest;
use Modules\OliveMillAndGrainMill\Http\Resources\SianMovementResource;
use Modules\OliveMillAndGrainMill\Http\Resources\TankResource;
use Modules\OliveMillAndGrainMill\Models\SianTank;
use OpenApi\Attributes as OA;

/**
 * Tanks and silos (synoptic map) and the operations between them: transfer / blend, bottling and rectification.
 */
final class TankController extends Controller
{
    use ApiResponse;
    use InteractsWithOliveMill;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/olive-mill/sian-tanks', operationId: 'oliveMillTanksIndex', summary: 'Tanks with stock, category, fill level and grid position (synoptic map)', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Tanks')])]
    public function index(MillQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', SianTank::class);

        return $this->successResponse(TankResource::collection($queries->tanks()), $this->message('listed'));
    }

    #[OA\Post(path: '/api/v1/olive-mill/sian-tanks', operationId: 'oliveMillTanksStore', summary: 'Register a tank', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 201, description: 'Tank')])]
    public function store(SaveTankRequest $request, SaveTankHandler $handler): JsonResponse
    {
        Gate::authorize('create', SianTank::class);

        return $this->createdResponse(new TankResource($handler->handle($request->validated())), $this->message('tank_saved'));
    }

    #[OA\Get(path: '/api/v1/olive-mill/sian-tanks/{id}', operationId: 'oliveMillTanksShow', summary: 'One tank', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Tank')])]
    public function show(string $id, MillQueries $queries): JsonResponse
    {
        $tank = $queries->tank($id);
        Gate::authorize('view', $tank);

        return $this->successResponse(new TankResource($tank), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/olive-mill/sian-tanks/{id}', operationId: 'oliveMillTanksUpdate', summary: 'Change a tank (capacity, position, active flag)', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Tank')])]
    public function update(SaveTankRequest $request, string $id, SaveTankHandler $handler): JsonResponse
    {
        Gate::authorize('update', SianTank::query()->findOrFail($id));

        return $this->successResponse(new TankResource($handler->handle($request->validated(), $id)), $this->message('tank_saved'));
    }

    #[OA\Delete(path: '/api/v1/olive-mill/sian-tanks/{id}', operationId: 'oliveMillTanksDestroy', summary: 'Delete an empty tank without register history', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    public function destroy(string $id, SaveTankHandler $handler): JsonResponse
    {
        Gate::authorize('delete', SianTank::query()->findOrFail($id));
        $handler->delete($id);

        return $this->successResponse(null, $this->message('tank_deleted'));
    }

    #[OA\Post(path: '/api/v1/olive-mill/sian-tanks/transfer', operationId: 'oliveMillTanksTransfer', summary: 'Transfer or blend oil between tanks (T01) with category compatibility check', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 201, description: 'Transfer')])]
    public function transfer(TransferTanksRequest $request, TankOperationsHandler $handler): JsonResponse
    {
        Gate::authorize('create', SianTank::class);

        $result = $handler->transfer(TransferBetweenTanksCommand::fromArray($request->validated(), $this->userId()));

        return $this->createdResponse([
            'movement' => new SianMovementResource($result['movement']),
            'downgraded' => $result['downgraded'],
            'from' => new TankResource($result['from']),
            'to' => new TankResource($result['to']),
        ], $this->message('transfer_done'));
    }

    #[OA\Post(path: '/api/v1/olive-mill/sian-tanks/package', operationId: 'oliveMillTanksPackage', summary: 'Bottle oil from a tank (S03) and generate the packaging lot', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 201, description: 'Packaging')])]
    public function package(PackageTankRequest $request, TankOperationsHandler $handler): JsonResponse
    {
        Gate::authorize('create', SianTank::class);

        $result = $handler->package(PackageFromTankCommand::fromArray($request->validated(), $this->userId()));

        return $this->createdResponse(['movement' => new SianMovementResource($result['movement']), 'tank' => new TankResource($result['tank'])], $this->message('packaged'));
    }

    #[OA\Post(path: '/api/v1/olive-mill/sian-tanks/adjust', operationId: 'oliveMillTanksAdjust', summary: 'Rectify a tank stock for natural loss (R01)', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 201, description: 'Rectification')])]
    public function adjust(AdjustTankRequest $request, TankOperationsHandler $handler): JsonResponse
    {
        Gate::authorize('create', SianTank::class);

        $result = $handler->adjust(AdjustTankStockCommand::fromArray($request->validated(), $this->userId()));

        return $this->createdResponse(['movement' => new SianMovementResource($result['movement']), 'tank' => new TankResource($result['tank'])], $this->message('adjusted'));
    }
}
