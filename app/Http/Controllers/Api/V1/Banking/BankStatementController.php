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

    public function show(string $id): JsonResponse
    {
        $statement = BankStatement::with('lines')->findOrFail($id);

        return $this->successResponse(new BankStatementResource($statement), __('banking.messages.retrieved'));
    }

    /**
     * Reconcile the incoming lines of a statement that are not reconciled yet.
     */
    public function reconcile(string $id): JsonResponse
    {
        $statement = BankStatement::findOrFail($id);
        $summary = $this->reconciliation->reconcileStatement($statement);

        return $this->successResponse(
            ['statement' => new BankStatementResource($statement->refresh()->load('lines')), 'reconciliation' => $summary],
            __('banking.messages.reconciled')
        );
    }

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

    public function unmatch(string $lineId): JsonResponse
    {
        $line = $this->reconciliation->unmatch(BankStatementLine::findOrFail($lineId));

        return $this->successResponse(new BankStatementLineResource($line), __('banking.messages.unmatched'));
    }
}
