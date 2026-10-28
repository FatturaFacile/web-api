<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Expenses\AttachReceiptAction;
use App\Actions\Expenses\CreateExpenseAction;
use App\Actions\Expenses\ExportExpensesAction;
use App\Actions\Expenses\UpdateExpenseAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\ExportExpensesRequest;
use App\Http\Requests\Expenses\StoreExpenseRequest;
use App\Http\Requests\Expenses\UpdateExpenseRequest;
use App\Http\Requests\Expenses\UploadExpenseAttachmentRequest;
use App\Http\Resources\ExpenseAttachmentResource;
use App\Http\Resources\ExpenseResource;
use App\Http\Responses\ApiResponse;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Services\Expenses\ExpenseReportingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ExpenseController extends Controller
{
    use ApiResponse, AuthorizesRequests;

    public function __construct(
        private readonly CreateExpenseAction $createExpense,
        private readonly UpdateExpenseAction $updateExpense,
        private readonly AttachReceiptAction $attachReceipt,
        private readonly ExportExpensesAction $exportExpenses,
        private readonly ExpenseReportingService $reportingService,
    ) {}

    /**
     * List expenses with filters and pagination.
     */
    #[OA\Get(
        path: '/api/v1/expenses',
        operationId: 'listExpenses',
        description: 'Retrieve a paginated list of tenant expenses, filterable by date range, category, status, project, ticket, or supplier.',
        summary: 'List expenses',
        security: [['bearerAuth' => []]],
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'project_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'ticket_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'service_report_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'submitted', 'approved', 'rejected'])),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Expenses retrieved successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expense::class);

        $query = Expense::query()
            ->with(['category.translations', 'contact', 'user', 'expenseAttachments'])
            ->withCount('expenseAttachments')
            ->when($request->filled('date_from'), fn ($q) => $q->where('date', '>=', $request->query('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->where('date', '<=', $request->query('date_to')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->query('category_id')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->query('project_id')))
            ->when($request->filled('ticket_id'), fn ($q) => $q->where('ticket_id', $request->query('ticket_id')))
            ->when($request->filled('service_report_id'), fn ($q) => $q->where('service_report_id', $request->query('service_report_id')))
            ->when($request->filled('contact_id'), fn ($q) => $q->where('contact_id', $request->query('contact_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->query('user_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $search = (string) $request->query('search');
                $q->where(function ($sub) use ($search): void {
                    $sub->where('expense_number', 'ilike', "%{$search}%")
                        ->orWhere('merchant_name', 'ilike', "%{$search}%")
                        ->orWhere('notes', 'ilike', "%{$search}%");
                });
            })
            ->latest('date')
            ->latest('created_at');

        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));
        $expenses = $query->paginate($perPage);

        return $this->successResponse(
            data: ExpenseResource::collection($expenses),
            message: 'Expenses retrieved successfully',
            meta: [
                'current_page' => $expenses->currentPage(),
                'last_page' => $expenses->lastPage(),
                'per_page' => $expenses->perPage(),
                'total' => $expenses->total(),
            ]
        );
    }

    /**
     * Create an expense (supports receipt photo/PDF upload in multipart form).
     */
    #[OA\Post(
        path: '/api/v1/expenses',
        operationId: 'createExpense',
        description: 'Register a new business expense in less than 30 seconds with optional receipt photo/document upload.',
        summary: 'Create expense',
        security: [['bearerAuth' => []]],
        tags: ['Expenses'],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data')),
        responses: [
            new OA\Response(response: 201, description: 'Expense created successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Validation errors', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $this->authorize('create', Expense::class);

        $receipts = $request->file('receipts') ?? $request->file('receipt');
        $expense = $this->createExpense->execute($request->validated(), $receipts);

        return $this->successResponse(
            data: new ExpenseResource($expense),
            message: 'Expense registered successfully',
            statusCode: HttpResponse::HTTP_CREATED
        );
    }

    /**
     * Retrieve single expense details.
     */
    #[OA\Get(
        path: '/api/v1/expenses/{id}',
        operationId: 'getExpense',
        description: 'Retrieve expense by unique UUID.',
        summary: 'Get expense details',
        security: [['bearerAuth' => []]],
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Expense retrieved successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Expense not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $expense = Expense::with(['category.translations', 'contact', 'user', 'ticket', 'serviceReport', 'expenseAttachments'])
            ->findOrFail($id);

        $this->authorize('view', $expense);

        return $this->successResponse(
            data: new ExpenseResource($expense),
            message: 'Expense retrieved successfully'
        );
    }

    /**
     * Update expense details.
     */
    #[OA\Put(
        path: '/api/v1/expenses/{id}',
        operationId: 'updateExpense',
        description: 'Update expense data before it is exported to the accountant.',
        summary: 'Update expense',
        security: [['bearerAuth' => []]],
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Expense updated successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Expense locked (exported)', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function update(UpdateExpenseRequest $request, string $id): JsonResponse
    {
        $expense = Expense::findOrFail($id);
        $this->authorize('update', $expense);

        $updated = $this->updateExpense->execute($expense, $request->validated());

        return $this->successResponse(
            data: new ExpenseResource($updated),
            message: 'Expense updated successfully'
        );
    }

    /**
     * Delete expense (soft delete).
     */
    #[OA\Delete(
        path: '/api/v1/expenses/{id}',
        operationId: 'deleteExpense',
        description: 'Delete an un-exported expense.',
        summary: 'Delete expense',
        security: [['bearerAuth' => []]],
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Expense deleted successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Cannot delete exported expense', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $expense = Expense::findOrFail($id);
        $this->authorize('delete', $expense);

        $expense->delete();

        return $this->successResponse(
            data: null,
            message: 'Expense deleted successfully'
        );
    }

    /**
     * Upload additional receipt photo or PDF attachment.
     */
    #[OA\Post(
        path: '/api/v1/expenses/{id}/attachments',
        operationId: 'uploadExpenseAttachment',
        description: 'Upload additional receipt photos or PDF vouchers for an existing expense.',
        summary: 'Upload receipt attachment',
        security: [['bearerAuth' => []]],
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Receipt attached successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        ]
    )]
    public function uploadAttachment(UploadExpenseAttachmentRequest $request, string $id): JsonResponse
    {
        $expense = Expense::findOrFail($id);
        $this->authorize('uploadAttachment', $expense);

        $files = $request->file('files') ?? [$request->file('file')];
        $createdAttachments = [];

        foreach ($files as $file) {
            if ($file) {
                $createdAttachments[] = $this->attachReceipt->execute(
                    $expense,
                    $file,
                    $request->boolean('is_receipt', true)
                );
            }
        }

        return $this->successResponse(
            data: ExpenseAttachmentResource::collection($createdAttachments),
            message: 'Receipt attachment(s) uploaded successfully',
            statusCode: HttpResponse::HTTP_CREATED
        );
    }

    /**
     * Download or stream receipt attachment.
     */
    #[OA\Get(
        path: '/api/v1/expenses/{id}/attachments/{attachmentId}/download',
        operationId: 'downloadExpenseAttachment',
        description: 'Download or view original receipt image or PDF voucher.',
        summary: 'Download receipt attachment',
        security: [['bearerAuth' => []]],
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'attachmentId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'inline', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File binary stream'),
            new OA\Response(response: 404, description: 'Attachment not found'),
        ]
    )]
    public function downloadAttachment(Request $request, string $id, string $attachmentId): Response
    {
        $expense = Expense::findOrFail($id);
        $this->authorize('view', $expense);

        /** @var ExpenseAttachment $attachment */
        $attachment = ExpenseAttachment::where('expense_id', $expense->id)->findOrFail($attachmentId);

        $disk = $attachment->getStorageDisk();
        abort_unless(Storage::disk($disk)->exists($attachment->path), 404, 'Receipt file not found on disk.');

        $content = Storage::disk($disk)->get($attachment->path);
        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';

        return response($content, 200, [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => "{$disposition}; filename=\"{$attachment->original_name}\"",
            'Content-Length' => (string) strlen($content),
        ]);
    }

    /**
     * Export expenses to accountant CSV or JSON format.
     */
    #[OA\Get(
        path: '/api/v1/expenses/export',
        operationId: 'exportExpenses',
        description: 'Export structured expense records to accountant CSV format or JSON.',
        summary: 'Export expenses to accountant',
        security: [['bearerAuth' => []]],
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'project_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['csv', 'json'], default: 'csv')),
            new OA\Parameter(name: 'mark_as_exported', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', default: true)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CSV file download or JSON data'),
        ]
    )]
    public function export(ExportExpensesRequest $request): Response|JsonResponse
    {
        $this->authorize('export', Expense::class);

        $result = $this->exportExpenses->execute($request->validated());

        if ($request->query('format') === 'json') {
            return $this->successResponse($result, 'Expenses exported successfully');
        }

        return response($result['content'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$result['filename']}\"",
            'Content-Length' => (string) strlen($result['content']),
        ]);
    }

    /**
     * Reporting summary: monthly, by category, by project, by supplier.
     */
    #[OA\Get(
        path: '/api/v1/expenses/reports/summary',
        operationId: 'getExpenseSummaryReport',
        description: 'Get aggregate expense statistics: monthly trends, expenses by category, expenses by project, expenses by supplier.',
        summary: 'Expense summary report',
        security: [['bearerAuth' => []]],
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Summary statistics retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        ]
    )]
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expense::class);

        $report = $this->reportingService->getSummaryReport(
            year: $request->filled('year') ? (int) $request->query('year') : null,
            dateFrom: $request->query('date_from'),
            dateTo: $request->query('date_to')
        );

        return $this->successResponse($report, 'Expense summary report generated successfully');
    }
}
