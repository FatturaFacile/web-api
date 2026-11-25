<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\StatutoryFinancials\Application\Commands\GenerateCashFlowStatementCommand;
use Modules\StatutoryFinancials\Application\Commands\GenerateCashFlowStatementHandler;
use Modules\StatutoryFinancials\Application\Commands\GenerateStatutoryBalanceWithRoundingQuadratureCommand;
use Modules\StatutoryFinancials\Application\Commands\GenerateStatutoryBalanceWithRoundingQuadratureHandler;
use Modules\StatutoryFinancials\Application\Queries\GetDossierHandler;
use Modules\StatutoryFinancials\Application\Queries\GetDossierQuery;
use Modules\StatutoryFinancials\Application\Queries\GetMinisterialPreviewHandler;
use Modules\StatutoryFinancials\Application\Queries\GetMinisterialPreviewQuery;
use Modules\StatutoryFinancials\Http\Controllers\Concerns\InteractsWithStatutoryFinancials;
use Modules\StatutoryFinancials\Http\Requests\GenerateCashFlowRequest;
use Modules\StatutoryFinancials\Http\Resources\DossierDetailResource;
use OpenApi\Attributes as OA;

/**
 * The statements of a dossier: generation with the rounding quadrature, the cash flow statement and the ministerial-style preview.
 */
final class StatementController extends Controller
{
    use ApiResponse;
    use InteractsWithStatutoryFinancials;

    #[OA\Post(path: '/api/v1/statutory-financials/dossiers/{id}/generate', operationId: 'statutoryFinancialsGenerateStatements', summary: 'Generate the reclassified statutory statements with the rounding quadrature', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Statements generated')])]
    public function generate(string $id, GenerateStatutoryBalanceWithRoundingQuadratureHandler $handler, GetDossierHandler $reader): JsonResponse
    {
        Gate::authorize('update', $this->dossier($id));

        $handler->handle(new GenerateStatutoryBalanceWithRoundingQuadratureCommand($id, $this->userId()));

        return $this->successResponse(new DossierDetailResource($reader->handle(new GetDossierQuery($id))), $this->message('generated'));
    }

    #[OA\Post(path: '/api/v1/statutory-financials/dossiers/{id}/cash-flow', operationId: 'statutoryFinancialsGenerateCashFlow', summary: 'Generate the cash flow statement (OIC 10 / FER 4)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Cash flow statement generated')])]
    public function cashFlow(string $id, GenerateCashFlowRequest $request, GenerateCashFlowStatementHandler $handler, GetDossierHandler $reader): JsonResponse
    {
        Gate::authorize('update', $this->dossier($id));

        $handler->handle(new GenerateCashFlowStatementCommand($id, $request->input('method'), $this->userId()));

        return $this->successResponse(new DossierDetailResource($reader->handle(new GetDossierQuery($id))), $this->message('cash_flow_generated'));
    }

    #[OA\Get(path: '/api/v1/statutory-financials/dossiers/{id}/preview', operationId: 'statutoryFinancialsMinisterialPreview', summary: 'Ministerial-style (TEBENI) preview of the dossier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Preview with taxonomy elements')])]
    public function preview(string $id, GetMinisterialPreviewHandler $handler): JsonResponse
    {
        Gate::authorize('view', $this->dossier($id));

        return $this->successResponse($handler->handle(new GetMinisterialPreviewQuery($id)));
    }
}
