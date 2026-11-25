<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\IndustrialLaundry\Application\Commands\SaveGarmentCommand;
use Modules\IndustrialLaundry\Application\Commands\SaveGarmentHandler;
use Modules\IndustrialLaundry\Application\Queries\GetEntityDetailHandler;
use Modules\IndustrialLaundry\Application\Queries\GetEntityDetailQuery;
use Modules\IndustrialLaundry\Application\Queries\GetGarmentsHandler;
use Modules\IndustrialLaundry\Application\Queries\GetGarmentsQuery;
use Modules\IndustrialLaundry\Http\Controllers\Concerns\InteractsWithLaundry;
use Modules\IndustrialLaundry\Http\Requests\ListFiltersRequest;
use Modules\IndustrialLaundry\Http\Requests\SaveGarmentRequest;
use Modules\IndustrialLaundry\Http\Resources\GarmentResource;
use Modules\IndustrialLaundry\Models\Garment;
use OpenApi\Attributes as OA;

/**
 * Tagged garments: register a tag, read the lifecycle and the history, edit the PPE certification.
 */
final class GarmentController extends Controller
{
    use ApiResponse;
    use InteractsWithLaundry;

    #[OA\Get(path: '/api/v1/industrial-laundry/garments', operationId: 'industrialLaundryListGarments', summary: 'List tagged garments', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], responses: [new OA\Response(response: 200, description: 'Garments, paginated')])]
    public function index(ListFiltersRequest $request, GetGarmentsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', Garment::class);

        return $this->paged($handler->handle(new GetGarmentsQuery(
            state: $request->input('state'),
            articleType: $request->input('article_type'),
            customerId: $request->input('customer_id'),
            search: $request->input('q'),
            perPage: $this->perPage(),
        )), GarmentResource::class);
    }

    #[OA\Post(path: '/api/v1/industrial-laundry/garments', operationId: 'industrialLaundryCreateGarment', summary: 'Register a garment with its RFID UHF EPC', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], responses: [new OA\Response(response: 201, description: 'Garment registered')])]
    public function store(SaveGarmentRequest $request, SaveGarmentHandler $handler): JsonResponse
    {
        Gate::authorize('create', Garment::class);

        $garment = $handler->handle(new SaveGarmentCommand($this->tenantId(), $this->userId(), $request->validated()));

        return $this->successResponse(new GarmentResource($garment), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/industrial-laundry/garments/{id}', operationId: 'industrialLaundryShowGarment', summary: 'Read a garment with its history', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Garment')])]
    public function show(string $id, GetEntityDetailHandler $handler): JsonResponse
    {
        $garment = $handler->handle(new GetEntityDetailQuery(GetEntityDetailQuery::Garment, $id));
        Gate::authorize('view', $garment);

        return $this->successResponse(new GarmentResource($garment), $this->message('listed'));
    }

    #[OA\Put(path: '/api/v1/industrial-laundry/garments/{id}', operationId: 'industrialLaundryUpdateGarment', summary: 'Edit the article, assignment or PPE certification of a garment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Garment updated')])]
    public function update(SaveGarmentRequest $request, string $id, SaveGarmentHandler $handler): JsonResponse
    {
        Gate::authorize('update', Garment::query()->findOrFail($id));

        $garment = $handler->handle(new SaveGarmentCommand($this->tenantId(), $this->userId(), $request->validated(), $id));

        return $this->successResponse(new GarmentResource($garment), $this->message('saved'));
    }
}
