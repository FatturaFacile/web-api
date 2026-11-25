<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\IndustrialLaundry\Application\Commands\SaveRollCageCommand;
use Modules\IndustrialLaundry\Application\Commands\SaveRollCageHandler;
use Modules\IndustrialLaundry\Application\Queries\GetRollCagesHandler;
use Modules\IndustrialLaundry\Application\Queries\GetRollCagesQuery;
use Modules\IndustrialLaundry\Http\Controllers\Concerns\InteractsWithLaundry;
use Modules\IndustrialLaundry\Http\Requests\ListFiltersRequest;
use Modules\IndustrialLaundry\Http\Requests\SaveRollCageRequest;
use Modules\IndustrialLaundry\Http\Resources\RollCageResource;
use Modules\IndustrialLaundry\Models\RollCage;
use OpenApi\Attributes as OA;

/**
 * Roll cages (returnable containers) and their tare weight: what the weighing station and the UHF gate middleware read.
 */
final class RollCageController extends Controller
{
    use ApiResponse;
    use InteractsWithLaundry;

    #[OA\Get(path: '/api/v1/industrial-laundry/roll-cages', operationId: 'industrialLaundryListRollCages', summary: 'List roll cages with their tare weight; filter by barcode to read the tare of one cage', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], responses: [new OA\Response(response: 200, description: 'Roll cages, paginated')])]
    public function index(ListFiltersRequest $request, GetRollCagesHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', RollCage::class);

        return $this->paged($handler->handle(new GetRollCagesQuery(search: $request->input('q'), barcode: $request->input('barcode'), perPage: $this->perPage())), RollCageResource::class);
    }

    #[OA\Post(path: '/api/v1/industrial-laundry/roll-cages', operationId: 'industrialLaundryCreateRollCage', summary: 'Register a roll cage with its tare weight', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], responses: [new OA\Response(response: 201, description: 'Roll cage created')])]
    public function store(SaveRollCageRequest $request, SaveRollCageHandler $handler): JsonResponse
    {
        Gate::authorize('create', RollCage::class);

        return $this->successResponse(new RollCageResource($handler->handle(new SaveRollCageCommand($this->tenantId(), $this->userId(), $request->validated()))), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/industrial-laundry/roll-cages/{id}', operationId: 'industrialLaundryUpdateRollCage', summary: 'Update the tare weight or limits of a roll cage', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Roll cage updated')])]
    public function update(SaveRollCageRequest $request, string $id, SaveRollCageHandler $handler): JsonResponse
    {
        Gate::authorize('update', RollCage::query()->findOrFail($id));

        return $this->successResponse(new RollCageResource($handler->handle(new SaveRollCageCommand($this->tenantId(), $this->userId(), $request->validated(), $id))), $this->message('saved'));
    }
}
