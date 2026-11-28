<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\DairyCheeseManufacturing\Exceptions\DairyCheeseException;
use Modules\DairyCheeseManufacturing\Http\Requests\SaveBreederRequest;
use Modules\DairyCheeseManufacturing\Http\Resources\BreederResource;
use Modules\DairyCheeseManufacturing\Models\Breeder;
use Modules\DairyCheeseManufacturing\Models\MilkDelivery;
use OpenApi\Attributes as OA;

/**
 * Milk producers (breeders).
 */
final class BreederController extends Controller
{
    use ApiResponse;
    use DairyControllerSupport;

    #[OA\Get(path: '/api/v1/dairy-cheese/breeders', operationId: 'dairyCheeseBreedersList', summary: 'List the breeders', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Breeders')])]
    public function index(): JsonResponse
    {
        $this->view();

        return $this->successResponse(BreederResource::collection(Breeder::query()->orderBy('code')->get()));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/breeders', operationId: 'dairyCheeseBreedersCreate', summary: 'Register a breeder', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 201, description: 'Breeder created')])]
    public function store(SaveBreederRequest $request): JsonResponse
    {
        $breeder = Breeder::query()->create($request->validated() + ['created_by' => $this->userId($request)]);

        return $this->successResponse(new BreederResource($breeder), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/dairy-cheese/breeders/{id}', operationId: 'dairyCheeseBreedersShow', summary: 'Show a breeder', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Breeder')])]
    public function show(string $id): JsonResponse
    {
        $this->view();

        return $this->successResponse(new BreederResource(Breeder::query()->findOrFail($id)));
    }

    #[OA\Put(path: '/api/v1/dairy-cheese/breeders/{id}', operationId: 'dairyCheeseBreedersUpdate', summary: 'Update a breeder', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Breeder updated')])]
    public function update(SaveBreederRequest $request, string $id): JsonResponse
    {
        $breeder = Breeder::query()->findOrFail($id);
        $breeder->fill($request->validated() + ['updated_by' => $this->userId($request)])->save();

        return $this->successResponse(new BreederResource($breeder), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/dairy-cheese/breeders/{id}', operationId: 'dairyCheeseBreedersDelete', summary: 'Delete a breeder without deliveries', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Breeder deleted')])]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->manage();
        $breeder = Breeder::query()->findOrFail($id);

        if (MilkDelivery::query()->where('breeder_id', $breeder->id)->exists()) {
            throw new DairyCheeseException('A breeder with deliveries cannot be deleted.', 'breeder_has_deliveries');
        }

        $breeder->delete();

        return $this->successResponse(null, $this->message('deleted'));
    }
}
