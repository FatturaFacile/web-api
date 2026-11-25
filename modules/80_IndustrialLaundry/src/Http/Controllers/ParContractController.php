<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\IndustrialLaundry\Application\Commands\SaveParContractCommand;
use Modules\IndustrialLaundry\Application\Commands\SaveParContractHandler;
use Modules\IndustrialLaundry\Application\Queries\GetEntityDetailHandler;
use Modules\IndustrialLaundry\Application\Queries\GetEntityDetailQuery;
use Modules\IndustrialLaundry\Application\Queries\GetParContractsHandler;
use Modules\IndustrialLaundry\Application\Queries\GetParContractsQuery;
use Modules\IndustrialLaundry\Http\Controllers\Concerns\InteractsWithLaundry;
use Modules\IndustrialLaundry\Http\Requests\ListFiltersRequest;
use Modules\IndustrialLaundry\Http\Requests\SaveParContractRequest;
use Modules\IndustrialLaundry\Http\Resources\ParContractResource;
use Modules\IndustrialLaundry\Models\ParContract;
use OpenApi\Attributes as OA;

/**
 * Customer linen par stock contracts of hotels, restaurants, clinics and nursing homes.
 */
final class ParContractController extends Controller
{
    use ApiResponse;
    use InteractsWithLaundry;

    #[OA\Get(path: '/api/v1/industrial-laundry/par-contracts', operationId: 'industrialLaundryListParContracts', summary: 'List customer linen par stock contracts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], responses: [new OA\Response(response: 200, description: 'Contracts, paginated')])]
    public function index(ListFiltersRequest $request, GetParContractsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', ParContract::class);

        return $this->paged($handler->handle(new GetParContractsQuery(
            customerId: $request->input('customer_id'),
            segment: $request->input('segment'),
            active: $request->has('active') ? $request->boolean('active') : null,
            search: $request->input('q'),
            perPage: $this->perPage(),
        )), ParContractResource::class);
    }

    #[OA\Post(path: '/api/v1/industrial-laundry/par-contracts', operationId: 'industrialLaundryCreateParContract', summary: 'Create a customer linen par stock contract', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], responses: [new OA\Response(response: 201, description: 'Contract created')])]
    public function store(SaveParContractRequest $request, SaveParContractHandler $handler): JsonResponse
    {
        Gate::authorize('create', ParContract::class);

        $contract = $handler->handle(new SaveParContractCommand($this->tenantId(), $this->userId(), $request->validated()));

        return $this->successResponse(new ParContractResource($contract->load('customer')), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/industrial-laundry/par-contracts/{id}', operationId: 'industrialLaundryShowParContract', summary: 'Read a par stock contract with its lines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Contract')])]
    public function show(string $id, GetEntityDetailHandler $handler): JsonResponse
    {
        $contract = $handler->handle(new GetEntityDetailQuery(GetEntityDetailQuery::ParContract, $id));
        Gate::authorize('view', $contract);

        return $this->successResponse(new ParContractResource($contract), $this->message('listed'));
    }

    #[OA\Put(path: '/api/v1/industrial-laundry/par-contracts/{id}', operationId: 'industrialLaundryUpdateParContract', summary: 'Update a par stock contract and replace its lines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Contract updated')])]
    public function update(SaveParContractRequest $request, string $id, SaveParContractHandler $handler): JsonResponse
    {
        Gate::authorize('update', ParContract::query()->findOrFail($id));

        $contract = $handler->handle(new SaveParContractCommand($this->tenantId(), $this->userId(), $request->validated(), $id));

        return $this->successResponse(new ParContractResource($contract->load('customer')), $this->message('saved'));
    }
}
