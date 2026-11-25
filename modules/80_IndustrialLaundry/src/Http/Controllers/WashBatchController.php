<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\IndustrialLaundry\Application\Commands\CertifyWashBatchCommand;
use Modules\IndustrialLaundry\Application\Commands\CertifyWashBatchHandler;
use Modules\IndustrialLaundry\Application\Commands\CreateWashBatchCommand;
use Modules\IndustrialLaundry\Application\Commands\CreateWashBatchHandler;
use Modules\IndustrialLaundry\Application\Queries\GetEntityDetailHandler;
use Modules\IndustrialLaundry\Application\Queries\GetEntityDetailQuery;
use Modules\IndustrialLaundry\Application\Queries\GetWashBatchesHandler;
use Modules\IndustrialLaundry\Application\Queries\GetWashBatchesQuery;
use Modules\IndustrialLaundry\Http\Controllers\Concerns\InteractsWithLaundry;
use Modules\IndustrialLaundry\Http\Requests\CertifyWashBatchRequest;
use Modules\IndustrialLaundry\Http\Requests\CreateWashBatchRequest;
use Modules\IndustrialLaundry\Http\Requests\ListFiltersRequest;
use Modules\IndustrialLaundry\Http\Resources\WashBatchResource;
use Modules\IndustrialLaundry\Models\WashBatch;
use OpenApi\Attributes as OA;

/**
 * UNI EN 14065 RABC wash batches and their hygiene certificates.
 */
final class WashBatchController extends Controller
{
    use ApiResponse;
    use InteractsWithLaundry;

    #[OA\Get(path: '/api/v1/industrial-laundry/wash-batches', operationId: 'industrialLaundryListWashBatches', summary: 'List the wash batches and their RABC certificates', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], responses: [new OA\Response(response: 200, description: 'Wash batches, paginated')])]
    public function index(ListFiltersRequest $request, GetWashBatchesHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', WashBatch::class);

        return $this->paged($handler->handle(new GetWashBatchesQuery($request->input('status'), $request->input('from'), $request->input('to'), $this->perPage())), WashBatchResource::class);
    }

    #[OA\Post(path: '/api/v1/industrial-laundry/wash-batches', operationId: 'industrialLaundryCreateWashBatch', summary: 'Open a wash batch from the soiled roll cages received', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], responses: [new OA\Response(response: 201, description: 'Wash batch created')])]
    public function store(CreateWashBatchRequest $request, CreateWashBatchHandler $handler): JsonResponse
    {
        Gate::authorize('create', WashBatch::class);

        return $this->successResponse(new WashBatchResource($handler->handle(new CreateWashBatchCommand($this->tenantId(), $this->userId(), $request->validated()))), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/industrial-laundry/wash-batches/{id}', operationId: 'industrialLaundryShowWashBatch', summary: 'Read a wash batch with the cages and garments it carried', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Wash batch')])]
    public function show(string $id, GetEntityDetailHandler $handler): JsonResponse
    {
        $batch = $handler->handle(new GetEntityDetailQuery(GetEntityDetailQuery::WashBatch, $id));
        Gate::authorize('view', $batch);

        return $this->successResponse(new WashBatchResource($batch), $this->message('listed'));
    }

    #[OA\Post(path: '/api/v1/industrial-laundry/wash-batches/{id}/certify', operationId: 'industrialLaundryCertifyWashBatch', summary: 'Certify a wash batch against UNI EN 14065 RABC from the measured temperature, time, dosing and microbial count', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Certification verdict')])]
    public function certify(CertifyWashBatchRequest $request, string $id, CertifyWashBatchHandler $handler): JsonResponse
    {
        Gate::authorize('update', WashBatch::query()->findOrFail($id));

        $batch = $handler->handle(new CertifyWashBatchCommand($id, $this->userId(), $request->validated()));

        return $this->successResponse(new WashBatchResource($batch), $this->message($batch->rabc_control_status->value === 'compliant' ? 'certified' : 'not_certified'));
    }
}
