<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\CreditManagement\Http\Controllers\Concerns\InteractsWithCreditManagement;
use Modules\CreditManagement\Models\CreditCollectionCase;
use Modules\CreditManagement\Services\CreditDashboardService;
use OpenApi\Attributes as OA;

/**
 * Credit dashboard and collector worklist.
 */
final class DashboardController extends Controller
{
    use InteractsWithCreditManagement;

    public function __construct(private readonly CreditDashboardService $dashboard) {}

    #[OA\Get(path: '/api/v1/credit-management/dashboard', operationId: 'showCreditDashboard', description: 'DSO (open receivables over the invoiced value of the last 90 days, times 90), receivables and overdue by ageing bucket, customers over their limit, open cases by status, promises, disputes, pending limit requests, rating distribution, insurance and the top overdue customers.', summary: 'Credit dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], responses: [
        new OA\Response(response: 200, description: 'Credit dashboard', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditDashboard')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
    ])]
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', CreditCollectionCase::class);

        return response()->json(['data' => $this->dashboard->summary($this->tenantId(), Carbon::today())]);
    }

    #[OA\Get(path: '/api/v1/credit-management/worklist', operationId: 'showCreditCollectorWorklist', description: 'Open cases, the ones with an action due first, then by priority and outstanding amount. Filters: mine (the cases of the user), collector_id, due_only (action due today or earlier). Not paginated.', summary: 'Collector worklist', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], responses: [
        new OA\Response(response: 200, description: 'Collector worklist', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CreditWorklistItem'))])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
    ])]
    public function worklist(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CreditCollectionCase::class);
        $filters = $request->validate(['mine' => ['nullable', 'boolean'], 'collector_id' => ['nullable', 'uuid'], 'due_only' => ['nullable', 'boolean']]);
        $collector = (bool) ($filters['mine'] ?? false) ? $this->userId() : ($filters['collector_id'] ?? null);

        return response()->json(['data' => $this->dashboard->worklist($this->tenantId(), Carbon::today(), $collector, (bool) ($filters['due_only'] ?? false))]);
    }
}
