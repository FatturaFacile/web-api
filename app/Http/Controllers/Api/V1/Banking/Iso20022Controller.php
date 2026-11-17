<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Banking;

use App\Actions\Banking\ImportBankStatement;
use App\DTOs\Banking\ParsedStatement;
use App\Exceptions\BankStatementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\ExportPain001Request;
use App\Http\Requests\Banking\ImportIso20022Request;
use App\Http\Requests\Banking\ReconciliationTransactionsRequest;
use App\Http\Resources\BankStatementLineResource;
use App\Http\Responses\ApiResponse;
use App\Models\BankStatementLine;
use App\Services\Banking\BankStatementParserManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\Treasury\Actions\ExportSupplierPaymentsAction;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * ISO 20022 banking: camt.053 / camt.054 import with auto-reconciliation, the reconciliation work list and the
 * pain.001 payment file of approved supplier invoices.
 */
class Iso20022Controller extends Controller
{
    use ApiResponse;

    #[OA\Post(
        path: '/api/v1/banking/iso20022/import',
        operationId: 'importIso20022Statement',
        description: 'Import an ISO 20022 camt.053 statement or camt.054 notification (versions .04 and .08) and, unless auto_reconcile is false, reconcile its credits and debits with the open invoices of the tenant. Importing is idempotent: the same file is refused and a movement already held by an earlier statement is skipped by its bank reference (AcctSvcrRef / NtryRef).',
        summary: 'Import camt.053 / camt.054',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(required: ['file'], properties: [
                new OA\Property(property: 'file', type: 'string', format: 'binary'),
                new OA\Property(property: 'auto_reconcile', type: 'boolean', default: true),
            ])
        )),
        tags: ['Banking', 'Reconciliation'],
        responses: [
            new OA\Response(response: 201, description: 'Statement imported', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Not an ISO 20022 statement, or already imported', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function import(ImportIso20022Request $request, ImportBankStatement $import, BankStatementParserManager $parsers): JsonResponse
    {
        $content = (string) $request->file('file')->get();

        try {
            if (! in_array($parsers->detect($content), [ParsedStatement::FORMAT_CAMT053, ParsedStatement::FORMAT_CAMT054], true)) {
                return $this->problemDetailsResponse(__('banking.problem_titles.import'), __('banking.errors.not_iso20022'), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $result = $import->execute($content, $request->boolean('auto_reconcile', true));
        } catch (BankStatementException $e) {
            return $this->problemDetailsResponse(__('banking.problem_titles.import'), $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $statement = $result['statement'];
        $lines = $statement->lines();
        $reconciled = (clone $lines)->where('reconciled', true)->count();
        $stored = (clone $lines)->count();

        return $this->successResponse([
            'statement_id' => $statement->id,
            'format' => $statement->source_format,
            'account_iban' => $statement->account_iban,
            'currency' => $statement->currency,
            'statement_date' => CarbonImmutable::parse($statement->statement_date)->toDateString(),
            'opening_balance' => $statement->opening_balance !== null ? (float) $statement->opening_balance : null,
            'closing_balance' => $statement->closing_balance !== null ? (float) $statement->closing_balance : null,
            'entries' => (int) $statement->total_entries,
            'stored' => $stored,
            'duplicates' => $result['duplicates'],
            'reconciled' => $reconciled,
            'unmatched' => $stored - $reconciled,
            'auto_reconciled' => $result['reconciliation'] !== null,
            'status' => $statement->status,
        ], __('banking.messages.imported'), Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/banking/reconciliation/transactions',
        operationId: 'listReconciliationTransactions',
        description: 'Bank transactions of the imported statements, newest first. status=unmatched is the manual matching queue; link a transaction with POST /api/v1/banking/reconciliation/transactions/{lineId}/match.',
        summary: 'List bank transactions',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Banking', 'Reconciliation'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['unmatched', 'matched', 'all'], default: 'unmatched')),
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['credit', 'debit'])),
            new OA\Parameter(name: 'statement_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [new OA\Response(response: 200, description: 'Transactions', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function transactions(ReconciliationTransactionsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $status = $filters['status'] ?? 'unmatched';
        $search = isset($filters['search']) ? '%'.strtolower($filters['search']).'%' : null;

        $page = BankStatementLine::query()
            ->when($status !== 'all', fn ($query) => $query->where('reconciled', $status === 'matched'))
            ->when(isset($filters['direction']), fn ($query) => $query->where('direction', $filters['direction']))
            ->when(isset($filters['statement_id']), fn ($query) => $query->where('bank_statement_id', $filters['statement_id']))
            ->when($search !== null, fn ($query) => $query->where(fn ($inner) => $inner
                ->whereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(counterpart_name, \'\')) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(reference, \'\')) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(qr_reference, \'\')) LIKE ?', [$search])))
            ->orderByDesc('booking_date')
            ->orderBy('line_number')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return $this->successResponse(
            data: BankStatementLineResource::collection($page->getCollection()),
            message: __('banking.messages.listed'),
            meta: ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        );
    }

    #[OA\Post(
        path: '/api/v1/banking/iso20022/pain001/export',
        operationId: 'exportIso20022Pain001',
        description: 'Create the pain.001 credit transfer file (pain.001.001.03 or .09) of approved supplier invoices. Swiss QR-bill invoices are paid with QR-IBAN + QRR, standard IBANs with an ISO 11649 SCOR or unstructured text, in CHF or EUR. The XML is validated against the bundled ISO 20022 schema before it is frozen; nothing is paid until the bank confirms.',
        summary: 'Export pain.001 payment file',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['invoice_ids'],
            properties: [
                new OA\Property(property: 'invoice_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                new OA\Property(property: 'version', type: 'string', enum: ['03', '09'], default: '09'),
                new OA\Property(property: 'scheme', type: 'string', enum: ['ch', 'it'], default: 'ch'),
                new OA\Property(property: 'execution_date', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid', nullable: true),
            ]
        )),
        tags: ['Banking', 'Payments'],
        responses: [
            new OA\Response(response: 201, description: 'Payment file generated and validated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Invoices cannot be paid, or the file does not validate', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function pain001Export(ExportPain001Request $request, ExportSupplierPaymentsAction $export): JsonResponse
    {
        $data = $request->validated();
        $result = $export->export([
            'invoice_ids' => array_values($data['invoice_ids']),
            'format' => ($data['scheme'] ?? 'ch') === 'it' ? 'sepa_pain_001_it' : 'iso_pain_001_ch',
            'pain_version' => $data['version'] ?? '09',
            'execution_date' => $data['execution_date'] ?? null,
            'bank_account_id' => $data['bank_account_id'] ?? null,
        ], (string) $request->user()?->id, $request->ip());

        return $this->successResponse(
            data: [...$result, 'schema_valid' => true, 'filename' => 'pain.001.001.'.$result['pain_version'].'-'.$result['message_id'].'.xml'],
            message: __('banking.messages.pain001_exported'),
            statusCode: Response::HTTP_CREATED,
        );
    }
}
