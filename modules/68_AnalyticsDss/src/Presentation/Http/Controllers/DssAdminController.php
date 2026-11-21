<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\AnalyticsDss\Infrastructure\Projection\MartProjector;
use Modules\AnalyticsDss\Infrastructure\Projection\MartReader;
use Modules\AnalyticsDss\Infrastructure\Projection\MartReconciler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Data mart administration (bi.dss.admin): the ledger reconciliation of the mart, a rebuild, and the legal entities the
 * filter bar offers.
 */
final class DssAdminController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(path: '/api/v1/analytics/dss/legal-entities', operationId: 'listDssLegalEntities', description: 'The legal entities known to the data mart, for the global filter bar.', summary: 'Legal entities of the mart', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Executive DSS'], responses: [
        new OA\Response(response: 200, description: 'List of {id, name, currency}', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
    ])]
    public function legalEntities(MartReader $reader): JsonResponse
    {
        return $this->successResponse($reader->legalEntities($this->tenantId()));
    }

    #[OA\Get(path: '/api/v1/analytics/dss/admin/reconciliation', operationId: 'getDssReconciliation', description: 'Compares the data mart with the General Ledger per account. A variance of 0.00 means the mart is exact.', summary: 'Reconcile the mart with the General Ledger', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Executive DSS'], responses: [
        new OA\Response(response: 200, description: 'variance, accounts_checked, mismatches, snapshot_variance', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
    ])]
    public function reconciliation(MartReconciler $reconciler): JsonResponse
    {
        return $this->successResponse($reconciler->reconcile($this->tenantId()));
    }

    #[OA\Post(path: '/api/v1/analytics/dss/admin/consolidate', operationId: 'consolidateDssMarts', description: 'Rebuilds the data mart of the tenant from the operational tables and returns the reconciliation.', summary: 'Rebuild the data mart', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Executive DSS'], responses: [
        new OA\Response(response: 200, description: 'documents projected and the reconciliation', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
    ])]
    public function consolidate(MartProjector $projector, MartReconciler $reconciler): JsonResponse
    {
        $documents = $projector->rebuildTenant($this->tenantId());

        return $this->successResponse(['documents_projected' => $documents, 'reconciliation' => $reconciler->reconcile($this->tenantId())], 'Data mart consolidated', Response::HTTP_OK);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }
}
