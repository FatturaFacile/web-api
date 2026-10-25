<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Banking;

use App\Actions\Banking\ImportBankStatement;
use App\Exceptions\BankStatementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\CategorizeLineRequest;
use App\Http\Requests\Banking\ImportBankStatementRequest;
use App\Http\Requests\Banking\ManualMatchRequest;
use App\Http\Resources\BankStatementLineResource;
use App\Http\Resources\BankStatementResource;
use App\Http\Responses\ApiResponse;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Services\Banking\BankReconciliationService;
use App\Services\Banking\ReconciliationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bank statements (CBI, CAMT.053, CAMT.054) of the current tenant and their reconciliation with invoices.
 */
class BankStatementController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BankReconciliationService $reconciliation) {}

    /**
     * Import a statement file; the format is detected from its content. `?auto_reconcile=1` also reconciles it.
     */
    #[OA\Post(
        path: '/api/v1/banking/statements/import',
        operationId: 'importBankStatement',
        description: 'Import a statement file (CBI or ISO 20022 CAMT); format is detected automatically. Set auto_reconcile=1 to trigger immediate matching.',
        summary: 'Import bank statement',
        security: [['bearerAuth' => []]],
        tags: ['Banking', 'Reconciliation'],
        parameters: [
            new OA\Parameter(name: 'auto_reconcile', in: 'query', required: false, description: 'Trigger automatic invoice reconciliation upon import', schema: new OA\Schema(type: 'boolean', default: false)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'Statement XML or CBI text file'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Statement imported successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid statement format', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function import(ImportBankStatementRequest $request, ImportBankStatement $import): JsonResponse
    {
        try {
            $result = $import->execute((string) $request->file('file')->get(), $request->boolean('auto_reconcile'));
        } catch (BankStatementException $e) {
            return $this->problemDetailsResponse(__('banking.problem_titles.import'), $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $statement = $result['statement']->load('lines');

        return $this->successResponse(
            ['statement' => new BankStatementResource($statement), 'reconciliation' => $result['reconciliation']],
            __('banking.messages.imported'),
            Response::HTTP_CREATED
        );
    }

    /**
     * Statements, newest first; filter with `status`, `source_format` and `iban`.
     */
    #[OA\Get(
        path: '/api/v1/banking/statements',
        operationId: 'getBankStatements',
        description: 'List imported bank statements with line reconciliation counters.',
        summary: 'List bank statements',
        security: [['bearerAuth' => []]],
        tags: ['Banking', 'Reconciliation'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'partially_reconciled', 'reconciled'])),
            new OA\Parameter(name: 'source_format', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['cbi', 'camt053', 'camt054'])),
            new OA\Parameter(name: 'iban', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Statements listed successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([BankStatement::DRAFT, BankStatement::PARTIALLY_RECONCILED, BankStatement::RECONCILED])],
            'source_format' => ['sometimes', Rule::in(['cbi', 'camt053', 'camt054'])],
            'iban' => ['sometimes', 'string', 'max:34'],
        ]);

        $statements = BankStatement::query()
            ->withCount(['lines as reconciled_lines_count' => fn ($query) => $query->where('reconciled', true)])
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['source_format']), fn ($query) => $query->where('source_format', $filters['source_format']))
            ->when(isset($filters['iban']), fn ($query) => $query->where('account_iban', strtoupper(str_replace(' ', '', $filters['iban']))))
            ->orderByDesc('statement_date')
            ->orderByDesc('created_at')
            ->get();

        return $this->successResponse(BankStatementResource::collection($statements), __('banking.messages.listed'));
    }

    #[OA\Get(
        path: '/api/v1/banking/statements/{id}',
        operationId: 'getBankStatementById',
        description: 'Get bank statement and its constituent transaction lines.',
        summary: 'Get bank statement details',
        security: [['bearerAuth' => []]],
        tags: ['Banking', 'Reconciliation'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Statement UUID or ID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Statement retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Statement not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $statement = BankStatement::with('lines')->findOrFail($id);

        return $this->successResponse(new BankStatementResource($statement), __('banking.messages.retrieved'));
    }

    /**
     * Reconcile the incoming lines of a statement that are not reconciled yet.
     */
    #[OA\Post(
        path: '/api/v1/banking/statements/{id}/reconcile',
        operationId: 'reconcileStatement',
        description: 'Run automatic reconciliation algorithm on unreconciled statement lines.',
        summary: 'Reconcile statement lines',
        security: [['bearerAuth' => []]],
        tags: ['Banking', 'Reconciliation'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Statement UUID or ID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Statement reconciled', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Statement not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function reconcile(string $id): JsonResponse
    {
        $statement = BankStatement::findOrFail($id);
        $summary = $this->reconciliation->reconcileStatement($statement);

        return $this->successResponse(
            ['statement' => new BankStatementResource($statement->refresh()->load('lines')), 'reconciliation' => $summary],
            __('banking.messages.reconciled')
        );
    }

    #[OA\Post(
        path: '/api/v1/banking/statements/lines/{lineId}/manual-match',
        operationId: 'manualMatchBankStatementLine',
        description: 'Manually associate a bank statement line with a sales or supplier invoice.',
        summary: 'Manual match statement line',
        security: [['bearerAuth' => []]],
        tags: ['Banking', 'Reconciliation'],
        parameters: [
            new OA\Parameter(name: 'lineId', in: 'path', required: true, description: 'Statement line UUID or ID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['invoice_id'],
                properties: [
                    new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'difference_handling', type: 'string', enum: ['none', 'discount', 'write_off'], default: 'none'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Line matched successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Line not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Document direction mismatch or validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function manualMatch(ManualMatchRequest $request, string $lineId): JsonResponse
    {
        $line = BankStatementLine::findOrFail($lineId);
        $statement = BankStatement::findOrFail($line->bank_statement_id);

        // Incoming lines are paid by customer invoices, outgoing ones settle received supplier invoices.
        $document = $line->isCredit() ? Invoice::find($request->validated('invoice_id')) : PassiveInvoice::find($request->validated('invoice_id'));

        if ($document === null) {
            throw ValidationException::withMessages(['invoice_id' => __('banking.errors.document_direction_mismatch')]);
        }

        $line = $this->reconciliation->manualMatch(
            $line,
            $document,
            (string) $request->validated('difference_handling', 'none'),
            ReconciliationSettings::forTenant($statement->tenant_id),
        );

        return $this->successResponse(new BankStatementLineResource($line), __('banking.messages.matched'));
    }

    /**
     * Book a line that has no invoice (taxes, payroll, bank expenses, internal transfers, other expenses).
     */
    #[OA\Post(
        path: '/api/v1/banking/statements/lines/{lineId}/categorize',
        operationId: 'categorizeBankStatementLine',
        description: 'Categorize and book a non-invoice bank statement line (e.g., bank fee, tax payment, payroll transfer).',
        summary: 'Categorize statement line',
        security: [['bearerAuth' => []]],
        tags: ['Banking', 'Reconciliation'],
        parameters: [
            new OA\Parameter(name: 'lineId', in: 'path', required: true, description: 'Statement line UUID or ID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['category'],
                properties: [
                    new OA\Property(property: 'category', type: 'string', example: 'bank_fees'),
                    new OA\Property(property: 'ledger_account_code', type: 'string', nullable: true, example: '6800'),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Line categorized', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Line not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function categorize(CategorizeLineRequest $request, string $lineId): JsonResponse
    {
        $line = BankStatementLine::findOrFail($lineId);

        $line = $this->reconciliation->categorize(
            $line,
            $request->validated('category'),
            $request->validated('ledger_account_code'),
            $request->validated('notes'),
            ReconciliationSettings::forTenant($line->tenant_id),
        );

        return $this->successResponse(new BankStatementLineResource($line), __('banking.messages.categorized'));
    }

    #[OA\Post(
        path: '/api/v1/banking/statements/lines/{lineId}/unmatch',
        operationId: 'unmatchBankStatementLine',
        description: 'Remove manual or automatic match from a statement line.',
        summary: 'Unmatch statement line',
        security: [['bearerAuth' => []]],
        tags: ['Banking', 'Reconciliation'],
        parameters: [
            new OA\Parameter(name: 'lineId', in: 'path', required: true, description: 'Statement line UUID or ID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Line unmatched', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Line not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function unmatch(string $lineId): JsonResponse
    {
        $line = $this->reconciliation->unmatch(BankStatementLine::findOrFail($lineId));

        return $this->successResponse(new BankStatementLineResource($line), __('banking.messages.unmatched'));
    }
}
