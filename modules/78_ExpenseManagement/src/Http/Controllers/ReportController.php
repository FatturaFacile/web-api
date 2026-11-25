<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\ExpenseManagement\Application\Commands\CreateExpenseReportCommand;
use Modules\ExpenseManagement\Application\Commands\CreateExpenseReportHandler;
use Modules\ExpenseManagement\Application\Commands\UpdateExpenseReportCommand;
use Modules\ExpenseManagement\Application\Commands\UpdateExpenseReportHandler;
use Modules\ExpenseManagement\Application\Queries\GetExpenseReportDetailHandler;
use Modules\ExpenseManagement\Application\Queries\GetExpenseReportDetailQuery;
use Modules\ExpenseManagement\Application\Queries\GetExpenseReportsHandler;
use Modules\ExpenseManagement\Application\Queries\GetExpenseReportsQuery;
use Modules\ExpenseManagement\Http\Controllers\Concerns\InteractsWithExpenses;
use Modules\ExpenseManagement\Http\Requests\ListFiltersRequest;
use Modules\ExpenseManagement\Http\Requests\SaveReportRequest;
use Modules\ExpenseManagement\Http\Resources\ReportResource;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Services\ReportService;
use OpenApi\Attributes as OA;

/**
 * Expense reports of employees: list, create, read, edit and withdraw.
 */
final class ReportController extends Controller
{
    use ApiResponse;
    use InteractsWithExpenses;

    #[OA\Get(path: '/api/v1/expense-management/reports', operationId: 'expenseManagementListReports', summary: 'List expense reports', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], responses: [new OA\Response(response: 200, description: 'Expense reports, paginated')])]
    public function index(ListFiltersRequest $request, GetExpenseReportsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', ExpenseReport::class);

        $page = $handler->handle(new GetExpenseReportsQuery(
            status: $request->input('status'),
            employeeId: $request->input('employee_id'),
            periodFrom: $request->input('period_from'),
            periodTo: $request->input('period_to'),
            search: $request->input('q'),
            restrictToUserId: $this->restrictToUser(),
            perPage: $this->perPage(),
        ));

        return $this->successResponse(ReportResource::collection($page->items()), $this->message('listed'), 200, [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]);
    }

    #[OA\Post(path: '/api/v1/expense-management/reports', operationId: 'expenseManagementCreateReport', summary: 'Create an expense report', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], responses: [new OA\Response(response: 201, description: 'Report created')])]
    public function store(SaveReportRequest $request, CreateExpenseReportHandler $handler): JsonResponse
    {
        Gate::authorize('create', ExpenseReport::class);

        $data = $request->validated();

        if (! $this->isManager()) {
            unset($data['employee_id']);
        }

        $report = $handler->handle(new CreateExpenseReportCommand($data, $this->tenantId(), $this->userId()));

        return $this->successResponse(new ReportResource($report->load('claimant:id,name')), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/expense-management/reports/{id}', operationId: 'expenseManagementShowReport', summary: 'Expense report with lines, per-diems, audit trail and payouts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Report detail')])]
    public function show(string $id, GetExpenseReportDetailHandler $handler): JsonResponse
    {
        $report = $handler->handle(new GetExpenseReportDetailQuery($id));
        Gate::authorize('view', $report);

        return $this->successResponse(new ReportResource($report));
    }

    #[OA\Put(path: '/api/v1/expense-management/reports/{id}', operationId: 'expenseManagementUpdateReport', summary: 'Update an expense report', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Report updated')])]
    public function update(SaveReportRequest $request, string $id, UpdateExpenseReportHandler $handler): JsonResponse
    {
        $this->findReport($id, 'update');
        $report = $handler->handle(new UpdateExpenseReportCommand($id, $request->validated(), $this->userId()));

        return $this->successResponse(new ReportResource($report->load('claimant:id,name')), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/expense-management/reports/{id}', operationId: 'expenseManagementDeleteReport', summary: 'Withdraw a draft expense report', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Report withdrawn')])]
    public function destroy(string $id, ReportService $reports): JsonResponse
    {
        $reports->delete($this->findReport($id, 'delete'), $this->userId());

        return $this->successResponse(null, $this->message('deleted'));
    }
}
