<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Modules\ExpenseManagement\Application\Commands\ApproveExpenseReportCommand;
use Modules\ExpenseManagement\Application\Commands\ApproveExpenseReportHandler;
use Modules\ExpenseManagement\Application\Commands\ExportExpenseToPayrollOrPain001Command;
use Modules\ExpenseManagement\Application\Commands\ExportExpenseToPayrollOrPain001Handler;
use Modules\ExpenseManagement\Application\Commands\RejectExpenseReportCommand;
use Modules\ExpenseManagement\Application\Commands\RejectExpenseReportHandler;
use Modules\ExpenseManagement\Application\Commands\SubmitExpenseReportCommand;
use Modules\ExpenseManagement\Application\Commands\SubmitExpenseReportHandler;
use Modules\ExpenseManagement\Domain\Enums\PayoutChannel;
use Modules\ExpenseManagement\Exceptions\ExpenseException;
use Modules\ExpenseManagement\Http\Controllers\Concerns\InteractsWithExpenses;
use Modules\ExpenseManagement\Http\Requests\ExportPayoutRequest;
use Modules\ExpenseManagement\Http\Requests\ReportDecisionRequest;
use Modules\ExpenseManagement\Http\Resources\PayoutResource;
use Modules\ExpenseManagement\Http\Resources\ReportResource;
use Modules\ExpenseManagement\Models\ExpensePayout;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Services\PayoutService;
use Modules\ExpenseManagement\Services\ReportService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Status changes of a report: submit, review, approve, reject, cancel, pay out and confirm the reimbursement.
 */
final class WorkflowController extends Controller
{
    use ApiResponse;
    use InteractsWithExpenses;

    #[OA\Post(path: '/api/v1/expense-management/reports/{id}/submit', operationId: 'expenseManagementSubmitReport', summary: 'Submit a report for approval', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Report submitted')])]
    public function submit(string $id, SubmitExpenseReportHandler $handler): JsonResponse
    {
        $this->findReport($id, 'update');

        return $this->respond($handler->handle(new SubmitExpenseReportCommand($id, $this->userId())), 'submitted');
    }

    #[OA\Post(path: '/api/v1/expense-management/reports/{id}/start-review', operationId: 'expenseManagementStartReview', summary: 'Mark a submitted report as under review', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Review started')])]
    public function startReview(string $id, ReportService $reports): JsonResponse
    {
        return $this->respond($reports->startReview($this->findReport($id, 'approve'), $this->userId()), 'saved');
    }

    #[OA\Post(path: '/api/v1/expense-management/reports/{id}/approve', operationId: 'expenseManagementApproveReport', summary: 'Approve a report', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Report approved')])]
    public function approve(ReportDecisionRequest $request, string $id, ApproveExpenseReportHandler $handler): JsonResponse
    {
        $this->findReport($id, 'approve'); // the policy refuses the claimant's own report (except for the owner)

        $amount = $request->input('approved_amount');

        return $this->respond($handler->handle(new ApproveExpenseReportCommand($id, $this->userId(), $amount === null ? null : (string) $amount, $request->input('comment'))), 'approved');
    }

    #[OA\Post(path: '/api/v1/expense-management/reports/{id}/reject', operationId: 'expenseManagementRejectReport', summary: 'Reject a report with a reason', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Report rejected')])]
    public function reject(ReportDecisionRequest $request, string $id, RejectExpenseReportHandler $handler): JsonResponse
    {
        $this->findReport($id, 'approve');

        return $this->respond($handler->handle(new RejectExpenseReportCommand($id, $this->userId(), (string) $request->input('reason'))), 'rejected');
    }

    #[OA\Post(path: '/api/v1/expense-management/reports/{id}/cancel', operationId: 'expenseManagementCancelReport', summary: 'Cancel a report', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Report cancelled')])]
    public function cancel(string $id, ReportService $reports): JsonResponse
    {
        return $this->respond($reports->cancel($this->findReport($id, 'update'), $this->userId()), 'saved');
    }

    #[OA\Post(path: '/api/v1/expense-management/reports/{id}/export-payout', operationId: 'expenseManagementExportPayout', summary: 'Pay out an approved report through a pain.001 file or payroll', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Payout recorded')])]
    public function exportPayout(ExportPayoutRequest $request, string $id, ExportExpenseToPayrollOrPain001Handler $handler): JsonResponse
    {
        $this->findReport($id, 'settle');
        $payout = $handler->handle(new ExportExpenseToPayrollOrPain001Command($id, PayoutChannel::from((string) $request->input('channel')), $this->userId(), $request->input('execution_date')));

        return $this->successResponse(new PayoutResource($payout), $this->message('exported'));
    }

    #[OA\Post(path: '/api/v1/expense-management/reports/{id}/confirm-reimbursement', operationId: 'expenseManagementConfirmReimbursement', summary: 'Confirm that the employee was reimbursed', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Reimbursement confirmed')])]
    public function confirmReimbursement(string $id, PayoutService $payouts): JsonResponse
    {
        return $this->respond($payouts->confirmReimbursement($this->findReport($id, 'settle'), $this->userId()), 'saved');
    }

    #[OA\Get(path: '/api/v1/expense-management/reports/{id}/payouts/{payoutId}/file', operationId: 'expenseManagementDownloadPayout', summary: 'Download the pain.001 file of a payout', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'payoutId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'XML file')])]
    public function downloadPayout(string $id, string $payoutId): StreamedResponse
    {
        $this->findReport($id, 'settle');
        $payout = ExpensePayout::query()->where('expense_report_id', $id)->findOrFail($payoutId);

        if ($payout->path === null || ! Storage::disk((string) $payout->disk)->exists($payout->path)) {
            throw new ExpenseException('receipt_not_found', 404);
        }

        return Storage::disk((string) $payout->disk)->download($payout->path, (string) $payout->file_name, ['Content-Type' => 'application/xml']);
    }

    private function respond(ExpenseReport $report, string $message): JsonResponse
    {
        return $this->successResponse(new ReportResource($report->fresh(['claimant:id,name', 'lines.category', 'lines.mileageClaim', 'perDiems', 'auditLogs', 'payouts'])), $this->message($message));
    }
}
