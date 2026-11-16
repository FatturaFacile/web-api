<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Governance\Models\GovRisk;
use Modules\Governance\Services\GovernanceDashboardService;
use Modules\Governance\Services\GovernanceMembers;
use OpenApi\Attributes as OA;

/**
 * The governance dashboard and the members of the company for the owner pickers.
 */
final class DashboardController extends Controller
{
    #[OA\Get(path: '/api/v1/governance/dashboard', operationId: 'showGovDashboard', description: 'Risk heatmap (25 cells likelihood x impact with counts, on the residual score where assessed or on the inherent score with basis=inherent), risks by rating, open findings by severity, overdue and upcoming remediation actions and obligations, control test pass rate of the last 12 months and the progress of the audit plan of the current year.', summary: 'Governance dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [
        new OA\Parameter(name: 'basis', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['residual', 'inherent'], default: 'residual')),
    ], responses: [
        new OA\Response(response: 200, description: 'Dashboard', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovDashboard')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
    ])]
    public function show(Request $request, GovernanceDashboardService $dashboard): JsonResponse
    {
        $this->authorize('viewAny', GovRisk::class);
        $basis = $request->query('basis') === 'inherent' ? 'inherent' : 'residual';

        return response()->json(['data' => $dashboard->summary($basis)]);
    }

    #[OA\Get(path: '/api/v1/governance/members', operationId: 'listGovMembers', description: 'The members of the company (id, name, role) to choose owners, auditors and verifiers.', summary: 'List members', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], responses: [
        new OA\Response(response: 200, description: 'Members', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GovMember'))])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
    ])]
    public function members(GovernanceMembers $members): JsonResponse
    {
        $this->authorize('viewAny', GovRisk::class);

        return response()->json(['data' => $members->all()]);
    }
}
