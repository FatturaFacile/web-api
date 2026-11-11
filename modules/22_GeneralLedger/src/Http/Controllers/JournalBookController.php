<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Services\JournalBookExporter;
use OpenApi\Attributes as OA;

/**
 * Libro giornale: the posted entries of a fiscal year in order of date and progressive journal number, one row per line,
 * in the accounting currency of the tenant, with the transaction currency and amounts of each line next to it.
 */
final class JournalBookController extends Controller
{
    #[OA\Get(
        path: '/api/v1/general-ledger/journal-book/export',
        operationId: 'exportGeneralLedgerJournalBook',
        description: 'CSV of the libro giornale of a fiscal year of the active tenant: posted and reversed entries ordered by posting date and progressive journal number, one row per line. debit/credit are in the accounting currency of the tenant (column currency); transaction_currency, transaction_debit, transaction_credit and exchange_rate give the line as booked in its own currency. Semicolons, UTF-8 BOM, CRLF, decimal commas. Optional from/to dates narrow the year.',
        summary: 'Export journal book (libro giornale)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Journals'],
        parameters: [
            new OA\Parameter(name: 'fiscal_year_id', in: 'query', required: true, description: 'Fiscal year of the active tenant', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'The role of the user does not read the ledger', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Fiscal year not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function export(Request $request, TenantContext $tenantContext, JournalBookExporter $exporter): Response
    {
        $validated = $request->validate([
            'fiscal_year_id' => ['required', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $tenantId = (string) $tenantContext->getTenantId();
        $fiscalYear = FiscalYear::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($validated['fiscal_year_id']);
        $fileName = $exporter->fileName($fiscalYear);

        return response($exporter->csv($tenantId, $fiscalYear, $validated['from'] ?? null, $validated['to'] ?? null), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }
}
