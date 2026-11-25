<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\StatutoryFinancials\Application\Commands\ComputeFinancialRatiosCommand;
use Modules\StatutoryFinancials\Application\Commands\ComputeFinancialRatiosHandler;
use Modules\StatutoryFinancials\Application\Queries\SimulateCrisisIndicatorsHandler;
use Modules\StatutoryFinancials\Application\Queries\SimulateCrisisIndicatorsQuery;
use Modules\StatutoryFinancials\Http\Controllers\Concerns\InteractsWithStatutoryFinancials;
use Modules\StatutoryFinancials\Http\Requests\WhatIfRequest;
use OpenApi\Attributes as OA;

/**
 * Financial ratios, six-month DSCR and crisis indicators (Art. 2086 c.c., Art. 725 CO) with the what-if simulator.
 */
final class IndicatorController extends Controller
{
    use ApiResponse;
    use InteractsWithStatutoryFinancials;

    #[OA\Get(path: '/api/v1/statutory-financials/dossiers/{id}/indicators', operationId: 'statutoryFinancialsShowIndicators', summary: 'Ratios and crisis indicators of a dossier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Indicators with alert level')])]
    public function show(string $id, SimulateCrisisIndicatorsHandler $handler): JsonResponse
    {
        Gate::authorize('view', $this->dossier($id));

        return $this->successResponse($handler->handle(new SimulateCrisisIndicatorsQuery($id)));
    }

    #[OA\Post(path: '/api/v1/statutory-financials/dossiers/{id}/indicators/simulate', operationId: 'statutoryFinancialsSimulateIndicators', summary: 'What-if simulation of the DSCR and the crisis indicators', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Simulated indicators')])]
    public function simulate(string $id, WhatIfRequest $request, SimulateCrisisIndicatorsHandler $handler): JsonResponse
    {
        Gate::authorize('view', $this->dossier($id));

        return $this->successResponse($handler->handle(new SimulateCrisisIndicatorsQuery($id, $request->whatIf())));
    }

    #[OA\Post(path: '/api/v1/statutory-financials/dossiers/{id}/indicators', operationId: 'statutoryFinancialsComputeIndicators', summary: 'Compute and store the ratios of the deposit package', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Indicators stored')])]
    public function compute(string $id, WhatIfRequest $request, ComputeFinancialRatiosHandler $handler): JsonResponse
    {
        Gate::authorize('update', $this->dossier($id));

        return $this->successResponse($handler->handle(new ComputeFinancialRatiosCommand($id, $request->whatIf(), true, $this->userId())), $this->message('indicators_computed'));
    }
}
