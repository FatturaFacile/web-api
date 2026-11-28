<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\DairyCheeseManufacturing\Http\Requests\SaveRackRequest;
use Modules\DairyCheeseManufacturing\Http\Resources\RackResource;
use Modules\DairyCheeseManufacturing\Models\AgingRack;
use Modules\DairyCheeseManufacturing\Services\CellarMapService;
use OpenApi\Attributes as OA;

/**
 * Spatial map of the aging cellar: rooms, racks (scalere), shelves (assi).
 */
final class RackController extends Controller
{
    use ApiResponse;
    use DairyControllerSupport;

    public function __construct(private readonly CellarMapService $cellar) {}

    #[OA\Get(path: '/api/v1/dairy-cheese/racks', operationId: 'dairyCheeseRacksList', summary: 'Cellar map: shelves with occupancy and the wheels on each', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Racks and cells')])]
    public function index(): JsonResponse
    {
        $this->view();

        return $this->successResponse(['racks' => RackResource::collection($this->cellar->racks())->resolve(), 'cells' => $this->cellar->cells()]);
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/racks', operationId: 'dairyCheeseRacksCreate', summary: 'Create a shelf', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 201, description: 'Shelf created')])]
    public function store(SaveRackRequest $request): JsonResponse
    {
        $rack = AgingRack::query()->create($request->validated() + ['created_by' => $this->userId($request)]);

        return $this->successResponse(new RackResource($rack), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/dairy-cheese/racks/{id}', operationId: 'dairyCheeseRacksUpdate', summary: 'Update a shelf', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Shelf updated')])]
    public function update(SaveRackRequest $request, string $id): JsonResponse
    {
        $rack = AgingRack::query()->findOrFail($id);
        $rack->fill($request->validated() + ['updated_by' => $this->userId($request)])->save();

        return $this->successResponse(new RackResource($rack), $this->message('saved'));
    }
}
