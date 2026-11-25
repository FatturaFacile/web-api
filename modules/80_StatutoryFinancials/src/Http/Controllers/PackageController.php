<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\StatutoryFinancials\Application\Commands\GeneratePdfADocumentsCommand;
use Modules\StatutoryFinancials\Application\Commands\GeneratePdfADocumentsHandler;
use Modules\StatutoryFinancials\Application\Commands\ValidateAndExportXbrlInstanceCommand;
use Modules\StatutoryFinancials\Application\Commands\ValidateAndExportXbrlInstanceHandler;
use Modules\StatutoryFinancials\Application\Commands\VerifyPdfACommand;
use Modules\StatutoryFinancials\Application\Commands\VerifyPdfAHandler;
use Modules\StatutoryFinancials\Application\Queries\DownloadArtifactHandler;
use Modules\StatutoryFinancials\Application\Queries\DownloadArtifactQuery;
use Modules\StatutoryFinancials\Http\Controllers\Concerns\InteractsWithStatutoryFinancials;
use Modules\StatutoryFinancials\Http\Requests\GeneratePdfARequest;
use Modules\StatutoryFinancials\Http\Resources\ArtifactResource;
use Modules\StatutoryFinancials\Http\Resources\PackageResource;
use OpenApi\Attributes as OA;

/**
 * The deposit package of a dossier: XBRL instance with its validation report, PDF/A documents and their verification.
 */
final class PackageController extends Controller
{
    use ApiResponse;
    use InteractsWithStatutoryFinancials;

    #[OA\Get(path: '/api/v1/statutory-financials/dossiers/{id}/package', operationId: 'statutoryFinancialsShowPackage', summary: 'Deposit package of a dossier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Package with artifacts')])]
    public function show(string $id): JsonResponse
    {
        $dossier = $this->dossier($id);
        Gate::authorize('view', $dossier);

        return $this->successResponse([
            'package' => $dossier->package === null ? null : new PackageResource($dossier->package),
            'artifacts' => ArtifactResource::collection($dossier->artifacts()->orderByDesc('created_at')->get()),
        ]);
    }

    #[OA\Post(path: '/api/v1/statutory-financials/dossiers/{id}/xbrl/export', operationId: 'statutoryFinancialsExportXbrl', summary: 'Validate and export the XBRL instance', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Instance exported with its validation report')])]
    public function exportXbrl(string $id, ValidateAndExportXbrlInstanceHandler $handler): JsonResponse
    {
        Gate::authorize('update', $this->dossier($id));

        $package = $handler->handle(new ValidateAndExportXbrlInstanceCommand($id, $this->userId()));

        return $this->successResponse(new PackageResource($package), $this->message('xbrl_exported'));
    }

    #[OA\Post(path: '/api/v1/statutory-financials/dossiers/{id}/pdfa', operationId: 'statutoryFinancialsGeneratePdfA', summary: 'Generate and verify PDF/A documents', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Documents generated')])]
    public function generatePdfA(string $id, GeneratePdfARequest $request, GeneratePdfADocumentsHandler $handler): JsonResponse
    {
        Gate::authorize('update', $this->dossier($id));

        $result = $handler->handle(new GeneratePdfADocumentsCommand($id, $request->input('kinds'), (array) $request->input('meeting', []), $this->userId()));

        return $this->successResponse(['package' => new PackageResource($result['package']), 'artifacts' => ArtifactResource::collection($result['artifacts'])], $this->message('pdfa_generated'));
    }

    #[OA\Post(path: '/api/v1/statutory-financials/dossiers/{id}/artifacts/{artifactId}/verify', operationId: 'statutoryFinancialsVerifyPdfA', summary: 'Verify a stored PDF/A document', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'artifactId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Verification result')])]
    public function verify(string $id, string $artifactId, VerifyPdfAHandler $handler): JsonResponse
    {
        Gate::authorize('update', $this->dossier($id));

        return $this->successResponse(new ArtifactResource($handler->handle(new VerifyPdfACommand($id, $artifactId))), $this->message('pdfa_verified'));
    }

    #[OA\Get(path: '/api/v1/statutory-financials/dossiers/{id}/artifacts/{artifactId}/download', operationId: 'statutoryFinancialsDownloadArtifact', summary: 'Download the XBRL instance or a PDF/A document', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'artifactId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'File')])]
    public function download(string $id, string $artifactId, DownloadArtifactHandler $handler): Response
    {
        Gate::authorize('view', $this->dossier($id));

        $result = $handler->handle(new DownloadArtifactQuery($id, $artifactId));

        return response($result['contents'], 200, [
            'Content-Type' => $result['artifact']->mime_type,
            'Content-Disposition' => 'attachment; filename="'.addslashes($result['artifact']->file_name).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
