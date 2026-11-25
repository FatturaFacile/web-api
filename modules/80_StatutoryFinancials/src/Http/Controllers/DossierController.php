<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\StatutoryFinancials\Application\Commands\CreateDossierCommand;
use Modules\StatutoryFinancials\Application\Commands\CreateDossierHandler;
use Modules\StatutoryFinancials\Application\Commands\DeleteDossierCommand;
use Modules\StatutoryFinancials\Application\Commands\DeleteDossierHandler;
use Modules\StatutoryFinancials\Application\Commands\TransitionDossierCommand;
use Modules\StatutoryFinancials\Application\Commands\TransitionDossierHandler;
use Modules\StatutoryFinancials\Application\Commands\UpdateDossierCommand;
use Modules\StatutoryFinancials\Application\Commands\UpdateDossierHandler;
use Modules\StatutoryFinancials\Application\Queries\CheckEligibilityHandler;
use Modules\StatutoryFinancials\Application\Queries\CheckEligibilityQuery;
use Modules\StatutoryFinancials\Application\Queries\GetDossierHandler;
use Modules\StatutoryFinancials\Application\Queries\GetDossierQuery;
use Modules\StatutoryFinancials\Application\Queries\GetDossiersHandler;
use Modules\StatutoryFinancials\Application\Queries\GetDossiersQuery;
use Modules\StatutoryFinancials\Http\Controllers\Concerns\InteractsWithStatutoryFinancials;
use Modules\StatutoryFinancials\Http\Requests\ListDossiersRequest;
use Modules\StatutoryFinancials\Http\Requests\StoreDossierRequest;
use Modules\StatutoryFinancials\Http\Requests\TransitionDossierRequest;
use Modules\StatutoryFinancials\Http\Resources\DossierDetailResource;
use Modules\StatutoryFinancials\Http\Resources\DossierResource;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use OpenApi\Attributes as OA;

/**
 * Statutory financial statement dossiers: list, create, read, configure, lifecycle and the dimensional threshold check.
 */
final class DossierController extends Controller
{
    use ApiResponse;
    use InteractsWithStatutoryFinancials;

    #[OA\Get(path: '/api/v1/statutory-financials/dossiers', operationId: 'statutoryFinancialsListDossiers', summary: 'List statutory financial statement dossiers', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], responses: [new OA\Response(response: 200, description: 'Dossiers, paginated')])]
    public function index(ListDossiersRequest $request, GetDossiersHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', StatutoryDossier::class);

        return $this->paged($handler->handle(new GetDossiersQuery(
            status: $request->input('status'),
            fiscalYearId: $request->input('fiscal_year_id'),
            standard: $request->input('standard'),
            perPage: $this->perPage(),
        )), DossierResource::class);
    }

    #[OA\Post(path: '/api/v1/statutory-financials/dossiers', operationId: 'statutoryFinancialsCreateDossier', summary: 'Create a statutory financial statement dossier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], responses: [new OA\Response(response: 201, description: 'Dossier created')])]
    public function store(StoreDossierRequest $request, CreateDossierHandler $handler): JsonResponse
    {
        Gate::authorize('create', StatutoryDossier::class);

        $dossier = $handler->handle(new CreateDossierCommand($request->validated(), $this->tenantId(), $this->userId()));

        return $this->successResponse(new DossierResource($dossier->load('fiscalYear')), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/statutory-financials/dossiers/{id}', operationId: 'statutoryFinancialsShowDossier', summary: 'Full state of a dossier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Dossier with statements, notes and package')])]
    public function show(string $id, GetDossierHandler $handler): JsonResponse
    {
        $dossier = $handler->handle(new GetDossierQuery($id));
        Gate::authorize('view', $dossier);

        return $this->successResponse(new DossierDetailResource($dossier));
    }

    #[OA\Put(path: '/api/v1/statutory-financials/dossiers/{id}', operationId: 'statutoryFinancialsUpdateDossier', summary: 'Configure a dossier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Dossier updated')])]
    public function update(string $id, StoreDossierRequest $request, UpdateDossierHandler $handler): JsonResponse
    {
        Gate::authorize('update', $this->dossier($id));

        $dossier = $handler->handle(new UpdateDossierCommand($id, $request->validated(), $this->userId()));

        return $this->successResponse(new DossierResource($dossier->load('fiscalYear')), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/statutory-financials/dossiers/{id}', operationId: 'statutoryFinancialsDeleteDossier', summary: 'Delete a dossier that is not locked', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Dossier deleted')])]
    public function destroy(string $id, DeleteDossierHandler $handler): JsonResponse
    {
        Gate::authorize('delete', $this->dossier($id));

        $handler->handle(new DeleteDossierCommand($id));

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Post(path: '/api/v1/statutory-financials/dossiers/{id}/transition', operationId: 'statutoryFinancialsTransitionDossier', summary: 'Lock, reopen or file a dossier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Status updated')])]
    public function transition(string $id, TransitionDossierRequest $request, TransitionDossierHandler $handler): JsonResponse
    {
        Gate::authorize('file', $this->dossier($id));

        $dossier = $handler->handle(new TransitionDossierCommand($id, (string) $request->input('target'), $request->input('reference')));

        return $this->successResponse(new DossierResource($dossier->load('fiscalYear')), $this->message('transitioned'));
    }

    #[OA\Get(path: '/api/v1/statutory-financials/dossiers/{id}/eligibility', operationId: 'statutoryFinancialsDossierEligibility', summary: 'Dimensional threshold check (micro, abbreviated, ordinary)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Eligibility and warnings')])]
    public function eligibility(string $id, ListDossiersRequest $request, CheckEligibilityHandler $handler): JsonResponse
    {
        Gate::authorize('view', $this->dossier($id));

        return $this->successResponse($handler->handle(new CheckEligibilityQuery($id, $request->input('standard'))));
    }
}
