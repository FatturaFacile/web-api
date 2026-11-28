<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\BondedWarehouses\Application\Commands\RunAeoReconciliationAndComplianceCheckCommand;
use Modules\BondedWarehouses\Application\Handlers\RunAeoReconciliationAndComplianceCheckHandler;
use Modules\BondedWarehouses\Application\Queries\DashboardQueries;
use Modules\BondedWarehouses\Application\Queries\RegistryQueries;
use Modules\BondedWarehouses\Http\Controllers\Concerns\InteractsWithBondedWarehouses;
use Modules\BondedWarehouses\Http\Requests\RunReconciliationRequest;
use Modules\BondedWarehouses\Http\Resources\AeoAuditResource;
use Modules\BondedWarehouses\Models\AeoComplianceAudit;
use OpenApi\Attributes as OA;

/**
 * AEO compliance console: dashboard, audit log and the WMS vs customs book reconciliation.
 */
final class AeoController extends Controller
{
    use ApiResponse;
    use InteractsWithBondedWarehouses;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/bonded-warehouses/aeo/dashboard', operationId: 'bondedWarehousesAeoDashboard', summary: 'AEO compliance KPIs', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 200, description: 'AEO dashboard')])]
    public function dashboard(DashboardQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', AeoComplianceAudit::class);

        return $this->successResponse($queries->aeoDashboard(), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/bonded-warehouses/aeo/audits', operationId: 'bondedWarehousesAeoAudits', summary: 'AEO audit log', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 200, description: 'Audits')])]
    public function audits(RegistryQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', AeoComplianceAudit::class);

        return $this->paged($queries->aeoAudits($this->filters(), $this->perPage()), AeoAuditResource::class);
    }

    #[OA\Post(path: '/api/v1/bonded-warehouses/aeo/reconciliation', operationId: 'bondedWarehousesAeoReconciliation', summary: 'Run the WMS vs customs book reconciliation and the compliance checks', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 201, description: 'Reconciliation result')])]
    public function reconcile(RunReconciliationRequest $request, RunAeoReconciliationAndComplianceCheckHandler $handler): JsonResponse
    {
        Gate::authorize('create', AeoComplianceAudit::class);

        $result = $handler->handle(RunAeoReconciliationAndComplianceCheckCommand::fromArray($request->validated(), $this->userId()));

        return $this->createdResponse([
            'audits' => AeoAuditResource::collection($result['audits']),
            'critical' => $result['critical'],
            'minor' => $result['minor'],
            'matched' => $result['matched'],
            'cgu_utilization_percent' => $result['cgu_utilization_percent'],
        ], $this->message('reconciliation_done'));
    }
}
