<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\ExpenseManagement\Application\Commands\AddExpenseLineCommand;
use Modules\ExpenseManagement\Application\Commands\AddExpenseLineHandler;
use Modules\ExpenseManagement\Application\Commands\RemoveExpenseLineCommand;
use Modules\ExpenseManagement\Application\Commands\RemoveExpenseLineHandler;
use Modules\ExpenseManagement\Application\Commands\UpdateExpenseLineCommand;
use Modules\ExpenseManagement\Application\Commands\UpdateExpenseLineHandler;
use Modules\ExpenseManagement\Http\Controllers\Concerns\InteractsWithExpenses;
use Modules\ExpenseManagement\Http\Requests\SaveLineRequest;
use Modules\ExpenseManagement\Http\Resources\LineResource;
use Modules\ExpenseManagement\Models\ExpenseReport;
use OpenApi\Attributes as OA;

/**
 * Itemised lines of an expense report. Every response carries the recalculated totals of the report in meta.report_totals.
 */
final class LineController extends Controller
{
    use ApiResponse;
    use InteractsWithExpenses;

    #[OA\Post(path: '/api/v1/expense-management/reports/{id}/lines', operationId: 'expenseManagementAddLine', summary: 'Add an expense line', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Line added')])]
    public function store(SaveLineRequest $request, string $id, AddExpenseLineHandler $handler): JsonResponse
    {
        $this->findReport($id, 'update');
        $line = $handler->handle(new AddExpenseLineCommand($id, $request->validated(), $this->userId()));

        return $this->successResponse(new LineResource($line), $this->message('saved'), 201, ['report_totals' => $this->totals($id)]);
    }

    #[OA\Put(path: '/api/v1/expense-management/reports/{id}/lines/{lineId}', operationId: 'expenseManagementUpdateLine', summary: 'Update an expense line', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'lineId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Line updated')])]
    public function update(SaveLineRequest $request, string $id, string $lineId, UpdateExpenseLineHandler $handler): JsonResponse
    {
        $this->findReport($id, 'update');
        $line = $handler->handle(new UpdateExpenseLineCommand($id, $lineId, $request->validated(), $this->userId()));

        return $this->successResponse(new LineResource($line), $this->message('saved'), 200, ['report_totals' => $this->totals($id)]);
    }

    #[OA\Delete(path: '/api/v1/expense-management/reports/{id}/lines/{lineId}', operationId: 'expenseManagementRemoveLine', summary: 'Remove an expense line', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'lineId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Line removed')])]
    public function destroy(string $id, string $lineId, RemoveExpenseLineHandler $handler): JsonResponse
    {
        $this->findReport($id, 'update');
        $handler->handle(new RemoveExpenseLineCommand($id, $lineId, $this->userId()));

        return $this->successResponse(null, $this->message('deleted'), 200, ['report_totals' => $this->totals($id)]);
    }

    /**
     * @return array<string, string>
     */
    private function totals(string $reportId): array
    {
        $report = ExpenseReport::query()->findOrFail($reportId);

        return [
            'total_amount' => $report->total_amount,
            'reimbursable_amount' => $report->reimbursable_amount,
            'non_reimbursable_amount' => $report->non_reimbursable_amount,
            'taxable_amount' => $report->taxable_amount,
        ];
    }
}
