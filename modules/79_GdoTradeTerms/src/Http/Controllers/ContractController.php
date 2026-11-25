<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\GdoTradeTerms\Application\Commands\SaveContractCommand;
use Modules\GdoTradeTerms\Application\Commands\SaveContractHandler;
use Modules\GdoTradeTerms\Application\Commands\TransitionContractCommand;
use Modules\GdoTradeTerms\Application\Commands\TransitionContractHandler;
use Modules\GdoTradeTerms\Application\Queries\GetContractDetailHandler;
use Modules\GdoTradeTerms\Application\Queries\GetContractDetailQuery;
use Modules\GdoTradeTerms\Application\Queries\GetContractProgressHandler;
use Modules\GdoTradeTerms\Application\Queries\GetContractProgressQuery;
use Modules\GdoTradeTerms\Application\Queries\GetContractsHandler;
use Modules\GdoTradeTerms\Application\Queries\GetContractsQuery;
use Modules\GdoTradeTerms\Http\Controllers\Concerns\InteractsWithGdo;
use Modules\GdoTradeTerms\Http\Requests\AsOfRequest;
use Modules\GdoTradeTerms\Http\Requests\ListFiltersRequest;
use Modules\GdoTradeTerms\Http\Requests\SaveContractRequest;
use Modules\GdoTradeTerms\Http\Requests\TransitionRequest;
use Modules\GdoTradeTerms\Http\Resources\ContractResource;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Services\ContractService;
use OpenApi\Attributes as OA;

/**
 * GDO framework contracts: list, create, read, edit, lifecycle and the progress towards the year-end rebates.
 */
final class ContractController extends Controller
{
    use ApiResponse;
    use InteractsWithGdo;

    #[OA\Get(path: '/api/v1/gdo-trade-terms/contracts', operationId: 'gdoTradeTermsListContracts', summary: 'List GDO framework contracts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], responses: [new OA\Response(response: 200, description: 'Contracts, paginated')])]
    public function index(ListFiltersRequest $request, GetContractsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', GdoContract::class);

        return $this->paged($handler->handle(new GetContractsQuery(
            status: $request->input('status'),
            contactId: $request->input('contact_id'),
            jurisdiction: $request->input('jurisdiction'),
            search: $request->input('q'),
            perPage: $this->perPage(),
        )), ContractResource::class);
    }

    #[OA\Post(path: '/api/v1/gdo-trade-terms/contracts', operationId: 'gdoTradeTermsCreateContract', summary: 'Create a GDO framework contract', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], responses: [new OA\Response(response: 201, description: 'Contract created')])]
    public function store(SaveContractRequest $request, SaveContractHandler $handler): JsonResponse
    {
        Gate::authorize('create', GdoContract::class);

        $contract = $handler->handle(new SaveContractCommand($request->validated(), $this->tenantId(), $this->userId()));

        return $this->successResponse(new ContractResource($contract->load('contact')), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/gdo-trade-terms/contracts/{id}', operationId: 'gdoTradeTermsShowContract', summary: 'GDO contract with rebate scales and promotions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Contract detail')])]
    public function show(string $id, GetContractDetailHandler $handler): JsonResponse
    {
        $contract = $handler->handle(new GetContractDetailQuery($id));
        Gate::authorize('view', $contract);

        return $this->successResponse(new ContractResource($contract));
    }

    #[OA\Put(path: '/api/v1/gdo-trade-terms/contracts/{id}', operationId: 'gdoTradeTermsUpdateContract', summary: 'Update a GDO contract', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Contract updated')])]
    public function update(SaveContractRequest $request, string $id, SaveContractHandler $handler): JsonResponse
    {
        Gate::authorize('update', GdoContract::query()->findOrFail($id));

        $contract = $handler->handle(new SaveContractCommand($request->validated(), $this->tenantId(), $this->userId(), $id));

        return $this->successResponse(new ContractResource($contract->load(['contact', 'scales.tiers', 'promotions'])), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/gdo-trade-terms/contracts/{id}', operationId: 'gdoTradeTermsDeleteContract', summary: 'Delete a draft GDO contract', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Contract deleted')])]
    public function destroy(string $id, ContractService $contracts): JsonResponse
    {
        $contract = GdoContract::query()->findOrFail($id);
        Gate::authorize('delete', $contract);
        $contracts->delete($contract, $this->userId());

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Post(path: '/api/v1/gdo-trade-terms/contracts/{id}/transition', operationId: 'gdoTradeTermsTransitionContract', summary: 'Activate, suspend, resume, close or cancel a contract', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Contract moved')])]
    public function transition(TransitionRequest $request, string $id, TransitionContractHandler $handler): JsonResponse
    {
        Gate::authorize('update', GdoContract::query()->findOrFail($id));

        $contract = $handler->handle(new TransitionContractCommand($id, (string) $request->validated('action'), $this->userId()));

        return $this->successResponse(new ContractResource($contract->load(['contact', 'scales.tiers', 'promotions'])), $this->message('transitioned'));
    }

    #[OA\Get(path: '/api/v1/gdo-trade-terms/contracts/{id}/progress', operationId: 'gdoTradeTermsContractProgress', summary: 'Rebate scale attainment, year-end projection and contributions earned to date', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Progress of the contract')])]
    public function progress(AsOfRequest $request, string $id, GetContractProgressHandler $handler): JsonResponse
    {
        Gate::authorize('view', GdoContract::query()->findOrFail($id));

        return $this->successResponse($handler->handle(new GetContractProgressQuery($id, $request->input('as_of'))));
    }
}
