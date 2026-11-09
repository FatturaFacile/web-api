<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\DataExport\Services\ExcelCsvWriter;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use OpenApi\Attributes as OA;

/**
 * Libro giornale: the posted entries of a fiscal year in order of date and progressive journal number, one row per line,
 * in the accounting currency of the tenant, with the transaction currency and amounts of each line next to it.
 */
final class JournalBookController extends Controller
{
    /** @var list<string> */
    private const array Columns = [
        'journal_number', 'posting_date', 'entry_number', 'document_date', 'document_number', 'journal_type', 'status',
        'entry_description', 'line_number', 'account_code', 'account_name', 'line_description', 'debit', 'credit', 'currency',
        'transaction_currency', 'transaction_debit', 'transaction_credit', 'exchange_rate',
    ];

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
    public function export(Request $request, TenantContext $tenantContext, ExcelCsvWriter $writer): Response
    {
        $validated = $request->validate([
            'fiscal_year_id' => ['required', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $tenantId = (string) $tenantContext->getTenantId();
        $fiscalYear = FiscalYear::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($validated['fiscal_year_id']);

        $entries = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->whereNotNull('journal_number')
            ->whereIn('status', ['posted', 'reversed'])
            ->when($validated['from'] ?? null, fn ($query, string $from) => $query->whereDate('posting_date', '>=', $from))
            ->when($validated['to'] ?? null, fn ($query, string $to) => $query->whereDate('posting_date', '<=', $to))
            ->with(['lines' => fn ($query) => $query->withoutGlobalScopes()->with(['account' => fn ($account) => $account->withoutGlobalScopes()])])
            ->orderBy('posting_date')
            ->orderBy('journal_number')
            ->get();

        $currency = TenantAccountingCurrency::of($tenantId);
        $rows = $entries->flatMap(fn (JournalEntry $entry): array => $entry->lines->map(fn (JournalLine $line): array => $this->row($entry, $line, $currency))->all());

        $fileName = 'libro-giornale-'.($fiscalYear->year_code ?: $fiscalYear->id).'.csv';

        return response($writer->write(self::Columns, $rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    /**
     * One CSV row of the libro giornale: the entry, the line in the accounting currency, then the line as booked.
     *
     * @return list<mixed>
     */
    private function row(JournalEntry $entry, JournalLine $line, string $currency): array
    {
        return [
            $entry->journal_number,
            $entry->posting_date?->toDateString(),
            $entry->entry_number,
            $entry->document_date?->toDateString(),
            $entry->getAttribute('document_number'),
            $entry->journal_type,
            $entry->status,
            $entry->description,
            $line->line_number,
            $line->account?->account_code,
            $line->account?->name,
            $line->description,
            $this->amount((string) ($line->functional_debit ?? $line->debit_amount)),
            $this->amount((string) ($line->functional_credit ?? $line->credit_amount)),
            $currency,
            $line->currency,
            $this->amount((string) $line->debit_amount),
            $this->amount((string) $line->credit_amount),
            number_format((float) $line->exchange_rate, 6, ',', ''),
        ];
    }

    private function amount(string $value): string
    {
        return number_format((float) $value, 2, ',', '');
    }
}
