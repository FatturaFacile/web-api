<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Budgeting\Http\Controllers\Concerns\InteractsWithBudgeting;
use Modules\Budgeting\Models\BudgetVersion;
use Modules\Budgeting\Services\BudgetPeriods;
use Modules\Budgeting\Services\ScenarioCompareService;
use Modules\Budgeting\Services\VarianceService;
use OpenApi\Attributes as OA;

/**
 * Budget versus actual of a version, with the actuals read from the general ledger.
 */
final class VarianceController extends Controller
{
    use InteractsWithBudgeting;

    public function __construct(private readonly VarianceService $variance) {}

    #[OA\Get(path: '/api/v1/budgeting/versions/{id}/variance', operationId: 'showBudgetVariance', description: 'Budget against the actuals of the general ledger up to a fiscal period, grouped by account, cost centre, project or period. The variance is actual minus budget; is_favorable follows the nature of the account (expenses under budget, revenues over budget). Actuals are taken for the accounts the version budgets. Filters: through_period (default 12), group_by (default account).', summary: 'Budget versus actual', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId'), new OA\Parameter(name: 'through_period', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 12, default: 12)), new OA\Parameter(name: 'group_by', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['account', 'cost_center', 'project', 'period'], default: 'account'))], responses: [
        new OA\Response(response: 200, description: 'Variance report', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BudgetVariance')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function show(Request $request, string $id): JsonResponse
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('view', $version);
        $filters = $request->validate(['through_period' => ['nullable', 'integer', 'min:1', 'max:12'], 'group_by' => ['nullable', Rule::in(VarianceService::GroupBy)]]);

        return response()->json(['data' => $this->variance->variance($version, (int) ($filters['through_period'] ?? BudgetPeriods::Count), (string) ($filters['group_by'] ?? 'account'))]);
    }

    #[OA\Get(path: '/api/v1/budgeting/versions/{id}/compare', operationId: 'compareBudgetScenarios', description: 'Budget of this version (the base) against the budget of another version of the company (the scenario), summed up to a fiscal period and grouped by account, cost centre, project or period. The delta is scenario minus base; delta_percent is null when the base is zero.', summary: 'Compare two scenarios', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId'), new OA\Parameter(name: 'with', description: 'The scenario version to compare against', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'through_period', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 12, default: 12)), new OA\Parameter(name: 'group_by', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['account', 'cost_center', 'project', 'period'], default: 'account'))], responses: [
        new OA\Response(response: 200, description: 'The comparison', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function compare(Request $request, string $id, ScenarioCompareService $scenarios): JsonResponse
    {
        $base = BudgetVersion::query()->findOrFail($id);
        $this->authorize('view', $base);
        $filters = $request->validate(['with' => ['required', 'uuid', 'not_in:'.$id], 'through_period' => ['nullable', 'integer', 'min:1', 'max:12'], 'group_by' => ['nullable', Rule::in(VarianceService::GroupBy)]]);
        $scenario = BudgetVersion::query()->findOrFail($filters['with']);
        $this->authorize('view', $scenario);

        return response()->json(['data' => $scenarios->compare($base, $scenario, (int) ($filters['through_period'] ?? BudgetPeriods::Count), (string) ($filters['group_by'] ?? 'account'))]);
    }
}
