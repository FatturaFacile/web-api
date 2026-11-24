<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\GeneralLedgerRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Accounting\GeneralLedgerExportService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The general ledger (prima nota) generated from the operational records of the current tenant: a paginated preview
 * and the export for accountants and external ERPs.
 */
class GeneralLedgerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly GeneralLedgerExportService $ledger) {}

    /**
     * Preview of the entries of a period of at most one year; the totals and the balance check cover the whole period,
     * not just the page. The period is read month by month and only the entries of the page are kept, so the memory
     * does not grow with the period; entry numbers are positions in the whole period and do not change between pages.
     */
    #[OA\Get(
        path: '/api/v1/accounting/general-ledger',
        operationId: 'getGeneralLedgerPreview',
        description: 'Preview general ledger (prima nota) journal entries with balanced debit/credit verification. The period (`from_date`, `to_date`) is at most one year; entry numbers are positions in the whole period and stay the same on every page.',
        summary: 'General ledger preview',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Accounting', 'General Ledger'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'source', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'account_code', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Ledger retrieved successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function index(GeneralLedgerRequest $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 50);
        $page = (int) $request->input('page', 1);
        $result = $this->ledger->page($request->from(), $request->to(), $request->source(), $request->input('account_code'), $page, $perPage);
        $total = $result['totals']['entries'];

        return $this->successResponse([
            'entries' => array_map(fn ($entry): array => $this->ledger->present($entry, $request->dateFormat()), $result['entries']),
            'totals' => $result['totals'],
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total_entries' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ], __('accounting.messages.ledger_retrieved'));
    }

    /**
     * Download the ledger: `?format=csv` (default) or `?format=json`.
     */
    #[OA\Get(
        path: '/api/v1/accounting/general-ledger/export',
        operationId: 'exportGeneralLedger',
        description: 'Export general ledger records for external audit and accountant software in CSV or JSON format. The period is at most one year; the CSV is streamed month by month.',
        summary: 'Export general ledger',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Accounting', 'General Ledger'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['csv', 'json'], default: 'csv')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'General ledger file stream or payload', content: new OA\MediaType(mediaType: 'text/csv')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function export(GeneralLedgerRequest $request): StreamedResponse|JsonResponse
    {
        if ($request->input('format', 'csv') === 'json') {
            $entries = $this->ledger->entries($request->from(), $request->to(), $request->source(), $request->input('account_code'));

            return $this->successResponse([
                'entries' => array_map(fn ($entry): array => $this->ledger->present($entry, $request->dateFormat()), $entries),
                'totals' => $this->ledger->totals($entries),
            ], __('accounting.messages.ledger_retrieved'));
        }

        $filename = "general-ledger-{$request->from()->toDateString()}-{$request->to()->toDateString()}.csv";
        $entries = $this->ledger->stream($request->from(), $request->to(), $request->source(), $request->input('account_code'));

        return response()->streamDownload(
            function () use ($entries, $request): void {
                $this->ledger->writeCsv($entries, $request->dateFormat(), function (string $chunk): void {
                    echo $chunk;
                });
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }
}
