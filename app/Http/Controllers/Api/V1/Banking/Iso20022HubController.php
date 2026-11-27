<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Banking;

use App\Actions\Banking\ImportBankStatement;
use App\DTOs\Banking\ParsedStatement;
use App\Exceptions\BankStatementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\ReconciliationExceptionsRequest;
use App\Http\Requests\Banking\UploadIso20022Request;
use App\Http\Resources\BankStatementResource;
use App\Http\Resources\ReconciliationExceptionResource;
use App\Http\Responses\ApiResponse;
use App\Models\BankStatementLine;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Services\Banking\BankStatementParserManager;
use App\Services\Banking\ReconciliationExceptionClassifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Modules\GeneralLedger\Exceptions\FiscalPeriodException;
use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ISO 20022 hub: one upload endpoint for camt.053 statements, camt.054 notifications and pain.002 payment status
 * reports (the type is detected from the content), and the review queue of the movements the automatic reconciliation
 * could not settle, by cause (Unmatched, AmountMismatch, DuplicatePayment, AlreadyPaid, InvalidReference).
 */
class Iso20022HubController extends Controller
{
    use ApiResponse;

    #[OA\Post(
        path: '/api/v1/banking/iso20022/upload',
        operationId: 'uploadIso20022Document',
        description: 'Upload a camt.053 statement, camt.054 notification or pain.002 status report. A statement is stored idempotently and reconciled (unless auto_reconcile is false); a pain.002 report is correlated with the exported pain.001 batch through OrgnlMsgId, OrgnlPmtInfId and OrgnlEndToEndId and settles or rejects its payments with the ISO 20022 reason code.',
        summary: 'Upload an ISO 20022 file',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(required: ['file'], properties: [
                new OA\Property(property: 'file', type: 'string', format: 'binary'),
                new OA\Property(property: 'auto_reconcile', type: 'boolean', default: true),
                new OA\Property(property: 'payment_batch_id', type: 'string', format: 'uuid'),
            ]),
        )),
        tags: ['Banking', 'Reconciliation'],
        responses: [
            new OA\Response(response: 201, description: 'The detected message type with the statement or payment report summary'),
            new OA\Response(response: 422, description: 'Unsupported, invalid or duplicate file'),
        ],
    )]
    public function upload(UploadIso20022Request $request, BankStatementParserManager $parsers, ImportBankStatement $import, PaymentFactoryServiceInterface $payments): JsonResponse
    {
        $file = $request->file('file');
        $content = (string) file_get_contents((string) $file?->getRealPath());
        $content = mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        $filename = (string) $file?->getClientOriginalName();
        $head = ltrim(substr($content, 0, 4096), "\xEF\xBB\xBF \t\r\n");

        try {
            if (preg_match('/pain\.002|CstmrPmtStsRpt/i', $head) === 1) {
                return $this->paymentReport($payments, $content, $filename, $request->input('payment_batch_id'), (string) $request->user()?->id);
            }

            if (preg_match('/pain\.001|CstmrCdtTrfInitn/i', $head) === 1) {
                return $this->problemDetailsResponse(__('banking.problem_titles.import'), __('banking.errors.pain001_not_importable'), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $format = $parsers->detect($content);

            if (! in_array($format, [ParsedStatement::FORMAT_CAMT053, ParsedStatement::FORMAT_CAMT054], true)) {
                return $this->problemDetailsResponse(__('banking.problem_titles.import'), __('banking.errors.not_iso20022'), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $result = $import->execute($content, $request->boolean('auto_reconcile', true));
        } catch (FiscalPeriodException $e) {
            return $e->render();
        } catch (BankStatementException|InvalidArgumentException $e) {
            return $this->problemDetailsResponse(__('banking.problem_titles.import'), $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $statement = $result['statement'];
        $lines = $statement->lines();
        $stored = (clone $lines)->count();
        $reconciled = (clone $lines)->where('reconciled', true)->count();

        return $this->successResponse([
            'type' => $format === ParsedStatement::FORMAT_CAMT054 ? 'camt.054' : 'camt.053',
            'filename' => $filename,
            'statement' => new BankStatementResource($statement),
            'reconciliation' => ['reconciled' => $reconciled, 'unmatched' => $stored - $reconciled],
            'duplicates' => $result['duplicates'],
            'payment_report' => null,
        ], __('banking.messages.imported'), Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/banking/reconciliation/exceptions',
        operationId: 'listReconciliationExceptions',
        description: 'The open bank movements with the cause the automatic reconciliation recorded (Unmatched, AmountMismatch, DuplicatePayment, AlreadyPaid, InvalidReference) and the document each one probably belongs to. meta.counts holds the number of movements per cause. Resolve a movement with POST /api/v1/banking/statements/lines/{lineId}/manual-match or /categorize.',
        summary: 'List reconciliation exceptions',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Banking', 'Reconciliation'],
        responses: [new OA\Response(response: 200, description: 'The review queue')],
    )]
    public function exceptions(ReconciliationExceptionsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $search = isset($filters['search']) ? '%'.strtolower($filters['search']).'%' : null;
        $base = fn (): Builder => BankStatementLine::query()
            ->where('reconciled', false)
            ->when(isset($filters['direction']), fn (Builder $query) => $query->where('direction', $filters['direction']))
            ->when(isset($filters['statement_id']), fn (Builder $query) => $query->where('bank_statement_id', $filters['statement_id']))
            ->when($search !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->whereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(counterpart_name, \'\')) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(reference, \'\')) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(qr_reference, \'\')) LIKE ?', [$search])));

        $counts = array_fill_keys(ReconciliationExceptionClassifier::CODES, 0);

        foreach ($base()->selectRaw('COALESCE(exception_code, ?) as code, COUNT(*) as total', [ReconciliationExceptionClassifier::UNMATCHED])->groupByRaw('COALESCE(exception_code, ?)', [ReconciliationExceptionClassifier::UNMATCHED])->get() as $row) {
            $counts[(string) $row->getAttribute('code')] = (int) $row->getAttribute('total');
        }

        $page = $base()
            ->when(isset($filters['code']), fn (Builder $query) => $filters['code'] === ReconciliationExceptionClassifier::UNMATCHED
                ? $query->where(fn (Builder $inner) => $inner->whereNull('exception_code')->orWhere('exception_code', ReconciliationExceptionClassifier::UNMATCHED))
                : $query->where('exception_code', $filters['code']))
            ->orderByDesc('booking_date')
            ->orderBy('line_number')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        $candidates = $this->candidates($page->getCollection()->pluck('exception_candidate_id')->filter()->unique()->values()->all());

        return $this->successResponse(
            data: $page->getCollection()->map(fn (BankStatementLine $line): ReconciliationExceptionResource => new ReconciliationExceptionResource($line, $candidates[$line->exception_candidate_id] ?? null)),
            message: __('banking.messages.listed'),
            meta: ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'counts' => $counts],
        );
    }

    private function paymentReport(PaymentFactoryServiceInterface $payments, string $content, string $filename, mixed $batchId, string $userId): JsonResponse
    {
        try {
            $result = $payments->applyPain002($content, is_string($batchId) ? $batchId : null, $userId);
        } catch (FiscalPeriodException $e) {
            return $e->render();
        }

        return $this->successResponse([
            'type' => 'pain.002',
            'filename' => $filename,
            'statement' => null,
            'reconciliation' => null,
            'duplicates' => null,
            'payment_report' => $result->toArray(),
        ], __('banking.messages.pain002_applied'), Response::HTTP_CREATED);
    }

    /**
     * The documents the queue points to, by identifier.
     *
     * @param  list<string>  $ids
     * @return array<string, array{id: string, invoice_number: string, kind: string, due_amount: float, currency: string}>
     */
    private function candidates(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $candidates = [];

        foreach (Invoice::query()->whereIn('id', $ids)->get() as $invoice) {
            $candidates[(string) $invoice->id] = [
                'id' => (string) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'kind' => 'customer',
                'due_amount' => (float) $invoice->outstandingAmount(),
                'currency' => (string) $invoice->currency,
            ];
        }

        foreach (PassiveInvoice::query()->whereIn('id', $ids)->get() as $invoice) {
            $candidates[(string) $invoice->id] = [
                'id' => (string) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'kind' => 'supplier',
                'due_amount' => round((float) $invoice->amountDue() - (float) $invoice->total_paid, 2),
                'currency' => (string) $invoice->currency,
            ];
        }

        return $candidates;
    }
}
