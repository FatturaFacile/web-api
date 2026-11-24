<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Illuminate\Support\Carbon;
use Modules\DataExport\Services\ExcelCsvWriter;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;

/**
 * Libro giornale of a fiscal year as CSV: the posted and reversed entries in order of date and progressive journal
 * number, one row per line, in the accounting currency of the tenant with the line as booked next to it. Shared by the
 * download endpoint and by the preservation module, which files the same bytes in the document library.
 */
final readonly class JournalBookExporter
{
    /** @var list<string> */
    private const array Columns = [
        'journal_number', 'posting_date', 'entry_number', 'document_date', 'document_number', 'journal_type', 'status',
        'entry_description', 'line_number', 'account_code', 'account_name', 'line_description', 'debit', 'credit', 'currency',
        'transaction_currency', 'transaction_debit', 'transaction_credit', 'exchange_rate',
    ];

    /**
     * Inject the CSV writer.
     */
    public function __construct(private ExcelCsvWriter $writer) {}

    /**
     * File name of the export of a fiscal year.
     */
    public function fileName(FiscalYear $fiscalYear): string
    {
        return 'libro-giornale-'.($fiscalYear->year_code ?: $fiscalYear->id).'.csv';
    }

    /**
     * The CSV of the year (optionally narrowed by posting date) of a tenant.
     */
    public function csv(string $tenantId, FiscalYear $fiscalYear, ?string $from = null, ?string $to = null): string
    {
        $entries = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->whereNotNull('journal_number')
            ->whereIn('status', ['posted', 'reversed'])
            ->when($from, fn ($query, string $date) => $query->whereDate('posting_date', '>=', $date))
            ->when($to, fn ($query, string $date) => $query->whereDate('posting_date', '<=', $date))
            ->with(['lines' => fn ($query) => $query->withoutGlobalScopes()->with(['account' => fn ($account) => $account->withoutGlobalScopes()])])
            ->orderBy('posting_date')
            ->orderBy('journal_number')
            ->get();

        $currency = TenantAccountingCurrency::of($tenantId);
        $rows = $entries->flatMap(fn (JournalEntry $entry): array => $entry->lines->map(fn (JournalLine $line): array => $this->row($entry, $line, $currency))->all());

        return $this->writer->write(self::Columns, $rows);
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
            $this->date($entry->posting_date),
            $entry->entry_number,
            $this->date($entry->document_date),
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

    private function date(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toDateString();
    }

    private function amount(string $value): string
    {
        return number_format((float) $value, 2, ',', '');
    }
}
