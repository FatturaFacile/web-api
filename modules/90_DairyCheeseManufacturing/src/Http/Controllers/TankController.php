<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\DairyCheeseManufacturing\Exceptions\DairyCheeseException;
use Modules\DairyCheeseManufacturing\Http\Requests\ManageRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\SaveTankRequest;
use Modules\DairyCheeseManufacturing\Http\Resources\TankResource;
use Modules\DairyCheeseManufacturing\Models\MilkTank;
use Modules\DairyCheeseManufacturing\Services\TankInventoryService;
use OpenApi\Attributes as OA;

/**
 * Milk tanks (silos) with their live volume.
 */
final class TankController extends Controller
{
    use ApiResponse;
    use DairyControllerSupport;

    public function __construct(private readonly TankInventoryService $inventory) {}

    #[OA\Get(path: '/api/v1/dairy-cheese/tanks', operationId: 'dairyCheeseTanksList', summary: 'List the tanks with their current volume', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Tanks')])]
    public function index(): JsonResponse
    {
        $this->view();

        return $this->successResponse(TankResource::collection(MilkTank::query()->orderBy('code')->get()));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/tanks', operationId: 'dairyCheeseTanksCreate', summary: 'Register a tank', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 201, description: 'Tank created')])]
    public function store(SaveTankRequest $request): JsonResponse
    {
        $tank = MilkTank::query()->create($request->validated() + ['created_by' => $this->userId($request)]);

        return $this->successResponse(new TankResource($tank), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/dairy-cheese/tanks/{id}', operationId: 'dairyCheeseTanksUpdate', summary: 'Update a tank', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Tank updated')])]
    public function update(SaveTankRequest $request, string $id): JsonResponse
    {
        $tank = MilkTank::query()->findOrFail($id);
        $tank->fill($request->validated() + ['updated_by' => $this->userId($request)])->save();

        return $this->successResponse(new TankResource($tank), $this->message('saved'));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/tanks/{id}/clean', operationId: 'dairyCheeseTanksClean', summary: 'Record the cleaning of an empty tank', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Tank cleaned')])]
    public function clean(ManageRequest $request, string $id): JsonResponse
    {
        $tank = MilkTank::query()->findOrFail($id);

        if ($this->inventory->volume($tank->id)['liters'] > 0) {
            throw new DairyCheeseException('Only an empty tank can be cleaned.', 'tank_not_empty');
        }

        $tank->forceFill(['last_cleaned_at' => now(), 'status' => 'active'])->save();

        return $this->successResponse(new TankResource($tank), $this->message('saved'));
    }
}
