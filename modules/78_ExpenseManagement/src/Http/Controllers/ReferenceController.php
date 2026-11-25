<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\ExpenseManagement\Application\Queries\GetAciVehicleRatesHandler;
use Modules\ExpenseManagement\Application\Queries\GetAciVehicleRatesQuery;
use Modules\ExpenseManagement\Application\Queries\GetEmployeeExpenseBalanceHandler;
use Modules\ExpenseManagement\Application\Queries\GetEmployeeExpenseBalanceQuery;
use Modules\ExpenseManagement\Application\Queries\GetExpensePolicyViolationsHandler;
use Modules\ExpenseManagement\Application\Queries\GetExpensePolicyViolationsQuery;
use Modules\ExpenseManagement\Http\Controllers\Concerns\InteractsWithExpenses;
use Modules\ExpenseManagement\Http\Requests\ListFiltersRequest;
use Modules\ExpenseManagement\Http\Resources\AciRateResource;
use Modules\ExpenseManagement\Models\ExpenseLine;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Services\LookupService;
use OpenApi\Attributes as OA;

/**
 * Reference data and read-only views: lookups, vehicle rates, policies, the employee balance and the policy violations.
 */
final class ReferenceController extends Controller
{
    use ApiResponse;
    use InteractsWithExpenses;

    #[OA\Get(path: '/api/v1/expense-management/lookups', operationId: 'expenseManagementLookups', summary: 'Categories, enumerations, jurisdiction and employees', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], responses: [new OA\Response(response: 200, description: 'Lookups')])]
    public function lookups(LookupService $lookups): JsonResponse
    {
        Gate::authorize('viewAny', ExpenseReport::class);

        return $this->successResponse($lookups->lookups($this->tenantId(), $this->userId(), $this->isManager()));
    }

    #[OA\Get(path: '/api/v1/expense-management/aci-rates', operationId: 'expenseManagementAciRates', summary: 'Cost-per-km rates of vehicles (ACI and TCS tables)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], responses: [new OA\Response(response: 200, description: 'Vehicle rates')])]
    public function rates(ListFiltersRequest $request, GetAciVehicleRatesHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', ExpenseReport::class);

        return $this->successResponse(AciRateResource::collection($handler->handle(new GetAciVehicleRatesQuery(
            source: $request->input('source'),
            vehicleCode: $request->input('vehicle_code'),
            fuel: $request->input('fuel'),
            vehicleClass: $request->input('vehicle_class'),
            search: $request->input('q'),
            onDate: $request->input('on_date'),
        ))));
    }

    #[OA\Get(path: '/api/v1/expense-management/policies', operationId: 'expenseManagementPolicies', summary: 'Expense policies and their caps', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], responses: [new OA\Response(response: 200, description: 'Policies')])]
    public function policies(LookupService $lookups): JsonResponse
    {
        Gate::authorize('viewAny', ExpenseReport::class);

        return $this->successResponse($lookups->policies());
    }

    #[OA\Get(path: '/api/v1/expense-management/balance', operationId: 'expenseManagementBalance', summary: 'Open reports, unpaid approvals and advances of an employee', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], responses: [new OA\Response(response: 200, description: 'Balance')])]
    public function balance(ListFiltersRequest $request, GetEmployeeExpenseBalanceHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', ExpenseReport::class);

        $requested = $request->input('employee_id');
        $userId = $this->isManager() && $requested !== null ? (string) $requested : $this->userId();
        $currency = (string) (Tenant::query()->whereKey($this->tenantId())->value('default_currency') ?? 'EUR');

        return $this->successResponse($handler->handle(new GetEmployeeExpenseBalanceQuery($userId, $currency)));
    }

    #[OA\Get(path: '/api/v1/expense-management/policy-violations', operationId: 'expenseManagementPolicyViolations', summary: 'Expense lines with policy violations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], responses: [new OA\Response(response: 200, description: 'Violations, paginated')])]
    public function violations(ListFiltersRequest $request, GetExpensePolicyViolationsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', ExpenseReport::class);

        $page = $handler->handle(new GetExpensePolicyViolationsQuery(
            reportId: $request->input('report_id'),
            severity: $request->input('severity'),
            employeeId: $request->input('employee_id'),
            restrictToUserId: $this->restrictToUser(),
            page: (int) $request->input('page', 1),
            perPage: $this->perPage(),
        ));

        $rows = $page->getCollection()->map(static fn (ExpenseLine $line): array => [
            'line_id' => $line->id,
            'report_id' => $line->expense_report_id,
            'report_number' => $line->report?->report_number,
            'employee' => $line->report?->claimant === null ? null : ['id' => $line->report->claimant->id, 'name' => $line->report->claimant->name],
            'expense_date' => $line->expense_date->toDateString(),
            'merchant_name' => $line->merchant_name,
            'amount' => $line->amount_in_base_currency,
            'violations' => $line->getAttribute('filtered_violations'),
        ])->values();

        return $this->successResponse($rows, $this->message('listed'), 200, [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]);
    }
}
