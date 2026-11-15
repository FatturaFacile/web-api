<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Budgeting\Http\Controllers\Concerns\InteractsWithBudgeting;
use Modules\Budgeting\Models\BudgetVersion;
use Modules\Budgeting\Services\BudgetingDashboardService;
use OpenApi\Attributes as OA;

/**
 * Budgeting overview: versions per status, budget against actuals so far and the largest adverse variances.
 */
final class DashboardController extends Controller
{
    use InteractsWithBudgeting;

    public function __construct(private readonly BudgetingDashboardService $dashboard) {}

    #[OA\Get(path: '/api/v1/budgeting/dashboard', operationId: 'showBudgetingDashboard', description: 'Versions per status and type, versions waiting for approval and, for the selected version (version_id, else the latest approved or locked budget, else the latest version), the budget and actuals up to through_period (default: the last started period of its fiscal year), the monthly series with cumulative values and the five largest adverse variances by account.', summary: 'Budgeting dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(name: 'version_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'through_period', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 0, maximum: 12))], responses: [
        new OA\Response(response: 200, description: 'Dashboard', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BudgetingDashboard')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BudgetVersion::class);
        $filters = $request->validate([
            'version_id' => ['nullable', 'uuid', Rule::exists('budget_versions', 'id')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')],
            'through_period' => ['nullable', 'integer', 'min:0', 'max:12'],
        ]);

        return response()->json(['data' => $this->dashboard->summary($this->tenantId(), $filters['version_id'] ?? null, isset($filters['through_period']) ? (int) $filters['through_period'] : null)]);
    }
}
