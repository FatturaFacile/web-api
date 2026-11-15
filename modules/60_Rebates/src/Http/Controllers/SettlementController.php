<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Modules\Rebates\Http\Controllers\Concerns\InteractsWithRebates;
use Modules\Rebates\Http\Resources\SettlementResource;
use Modules\Rebates\Models\RebateAgreement;
use Modules\Rebates\Models\RebateSettlement;
use Modules\Rebates\Services\RebateDashboardService;
use OpenApi\Attributes as OA;

/**
 * Settlements of the rebates and the rebates dashboard.
 */
final class SettlementController extends Controller
{
    use InteractsWithRebates;

    public function __construct(private readonly RebateDashboardService $dashboard) {}

    #[OA\Get(path: '/api/v1/rebates/settlements', operationId: 'listRebateSettlements', description: 'Settlements, the latest first. Filter: agreement_id.', summary: 'List settlements', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 200, description: 'Settlements', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/RebateSettlement'))])),
        new OA\Response(ref: '#/components/responses/RebatesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/RebatesForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RebateSettlement::class);
        $filters = $request->validate(['agreement_id' => ['nullable', 'uuid']]);

        return SettlementResource::collection(
            RebateSettlement::query()
                ->with('agreement')
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['agreement_id']), static fn ($query) => $query->where('agreement_id', $filters['agreement_id']))
                ->orderByDesc('settled_on')
                ->orderByDesc('created_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Get(path: '/api/v1/rebates/dashboard', operationId: 'showRebatesDashboard', description: 'Agreements by status, rebate accrued and not settled and rebate settled per direction, promotions by state and the agreements ending within 30 days.', summary: 'Rebates dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 200, description: 'Rebates dashboard', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RebateDashboard')])),
        new OA\Response(ref: '#/components/responses/RebatesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/RebatesForbidden', response: 403),
    ])]
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', RebateAgreement::class);

        return response()->json(['data' => $this->dashboard->summary($this->tenantId(), Carbon::today())]);
    }
}
