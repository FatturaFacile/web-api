<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\DTOs\Accounting\LedgerEntry;
use App\DTOs\Accounting\LedgerLine;
use App\Models\BankStatementLine;
use App\Models\Contact;
use App\Services\Banking\BankLineLedgerPosting;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Collection;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;

/**
 * The general ledger (prima nota) of a period, read from the single ledger of the tenant: the posted entries of
 * `journal_entries` (statutory ledger, reversals included), with their lines on the chart of accounts.
 *
 * Until April 2027 this export rebuilt the postings from the documents with its own default accounts (receivables 1200,
 * bank 1000, ...), which differed from the accounts the posting services use (1100, 1020, ...) and from figures such as
 * withholding, split payment or stamp duty. Reading the journal guarantees that the export shows exactly the figures of
 * the ledger, on the same accounts; a document that has not reached the ledger yet (see `general-ledger:post-pending`)
 * is not in the export either. The CSV and JSON contract is unchanged: header, numbering over the whole period, totals
 * and balance check.
 *
 * Amounts are the functional-currency amounts of the lines (equal to the booked amounts for entries in the accounting
 * currency), converted to integer cents with bcmath, so every entry balances exactly.
 */
class GeneralLedgerExportService
{
    public const string SOURCE_ALL = 'all';

    public const string SOURCE_INVOICES = 'invoices';

    public const string SOURCE_PAYMENTS = 'payments';

    public const string SOURCE_BANKING = 'banking';

    public const string DATE_ISO = 'YYYY-MM-DD';

    public const string DATE_EUROPEAN = 'DD/MM/YYYY';

    public const array HEADER = ['entry_number', 'entry_date', 'document_type', 'document_number', 'document_date', 'counterpart_name', 'tax_id', 'ledger_account_code', 'ledger_account_name', 'description', 'debit', 'credit', 'currency', 'vat_code'];

    /**
     * The source document types of each `source` filter. `all` takes every posted entry, manual ones included.
     *
     * @var array<string, list<string>>
     */
    public const array SOURCE_TYPES = [
        self::SOURCE_INVOICES => ['sales_invoice', 'credit_note', 'vendor_invoice', 'vendor_credit_note', 'vendor_invoice_reclassification'],
        self::SOURCE_PAYMENTS => ['customer_payment', 'supplier_payment', 'treasury_payment_line', 'treasury_collection', 'riba_unpaid_fee'],
        self::SOURCE_BANKING => [BankLineLedgerPosting::SOURCE, 'treasury_transfer', 'treasury_bank_fee', 'treasury_interest_income', 'treasury_interest_expense', 'treasury_cash_movement', 'treasury_cash_difference', 'treasury_fx_revaluation'],
    ];

    /**
     * The document type shown for the source types of the documents (the names the export always used).
     *
     * @var array<string, string>
     */
    private const array DOCUMENT_TYPES = [
        'sales_invoice' => 'invoice',
        'credit_note' => 'credit_note',
        'vendor_invoice' => 'supplier_invoice',
        'vendor_credit_note' => 'supplier_credit_note',
        'customer_payment' => 'payment',
        'supplier_payment' => 'supplier_payment',
    ];

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Whether a period fits in one preview or export: `to` is at most one year minus a day after `from`, so a calendar
     * (or fiscal) year fits.
     */
    public static function periodFits(CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $to->lessThan($from->addYear());
    }

    /**
     * The numbered entries of the period, in journal order. `$accountCode` keeps the entries that touch that account.
     *
     * @return array<int, LedgerEntry>
     */
    public function entries(CarbonImmutable $from, CarbonImmutable $to, string $source = self::SOURCE_ALL, ?string $accountCode = null): array
    {
        return iterator_to_array($this->stream($from, $to, $source, $accountCode), false);
    }

    /**
     * The numbered entries of the period, read one calendar month at a time.
     *
     * Each month is read in journal order (posting date, progressive journal number, creation) and numbered with a
     * counter that runs over the whole period, so an entry keeps its number across pages, filters by account (applied
     * after numbering) and the export. Memory holds one month, not the period.
     *
     * @return Generator<int, LedgerEntry>
     */
    public function stream(CarbonImmutable $from, CarbonImmutable $to, string $source = self::SOURCE_ALL, ?string $accountCode = null): Generator
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $currency = TenantAccountingCurrency::of($tenantId);
        $position = 0;

        for ($windowFrom = $from; $windowFrom->lessThanOrEqualTo($to); $windowFrom = $windowFrom->addMonthNoOverflow()->startOfMonth()) {
            $windowTo = $windowFrom->endOfMonth()->startOfDay()->min($to);

            foreach ($this->journalEntries($tenantId, $windowFrom, $windowTo, $source, $currency) as $entry) {
                $entry->number = sprintf('%06d', ++$position);

                if ($accountCode === null || $accountCode === '' || collect($entry->lines)->contains(fn (LedgerLine $line): bool => $line->accountCode === $accountCode)) {
                    yield $entry;
                }
            }
        }
    }

    /**
     * One page of the entries and the totals of the whole period, in a single pass over the stream: only the entries of
     * the page are kept.
     *
     * @return array{entries: array<int, LedgerEntry>, totals: array{entries: int, lines: int, total_debit: float, total_credit: float, balanced: bool, unbalanced_entries: array<int, string>}}
     */
    public function page(CarbonImmutable $from, CarbonImmutable $to, string $source, ?string $accountCode, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $totals = new LedgerTotals;
        $entries = [];

        foreach ($this->stream($from, $to, $source, $accountCode) as $index => $entry) {
            $totals->add($entry);

            if ($index >= $offset && $index < $offset + $perPage) {
                $entries[] = $entry;
            }
        }

        return ['entries' => $entries, 'totals' => $totals->toArray()];
    }

    /**
     * Totals of a set of entries, with the balance check of the whole period and of every entry.
     *
     * @param  iterable<int, LedgerEntry>  $entries
     * @return array{entries: int, lines: int, total_debit: float, total_credit: float, balanced: bool, unbalanced_entries: array<int, string>}
     */
    public function totals(iterable $entries): array
    {
        $totals = new LedgerTotals;

        foreach ($entries as $entry) {
            $totals->add($entry);
        }

        return $totals->toArray();
    }

    /**
     * One entry as an array, with its lines.
     *
     * @return array<string, mixed>
     */
    public function present(LedgerEntry $entry, string $dateFormat = self::DATE_ISO): array
    {
        return [
            'entry_number' => $entry->number,
            'entry_date' => $this->formatDate($entry->date, $dateFormat),
            'document_type' => $entry->documentType,
            'document_number' => $entry->documentNumber,
            'document_date' => $entry->documentDate === null ? null : $this->formatDate($entry->documentDate, $dateFormat),
            'counterpart_name' => $entry->counterpartName,
            'tax_id' => $entry->taxId,
            'currency' => $entry->currency,
            'total_debit' => $entry->totalDebit() / 100,
            'total_credit' => $entry->totalCredit() / 100,
            'lines' => array_map(fn (LedgerLine $line): array => [
                'ledger_account_code' => $line->accountCode,
                'ledger_account_name' => $line->accountName,
                'description' => $line->description,
                'debit' => $line->debit / 100,
                'credit' => $line->credit / 100,
                'vat_code' => $line->vatCode,
            ], $entry->lines),
        ];
    }

    /**
     * Write the CSV of the entries through `$write`: UTF-8 with a byte order mark (so Excel opens it correctly),
     * `;` as delimiter, `,` as decimal separator with two decimals, and the standard header.
     *
     * @param  iterable<int, LedgerEntry>  $entries
     * @param  callable(string): void  $write
     */
    public function writeCsv(iterable $entries, string $dateFormat, callable $write): void
    {
        $write("\xEF\xBB\xBF".implode(';', self::HEADER)."\r\n");

        foreach ($entries as $entry) {
            foreach ($entry->lines as $line) {
                $write(implode(';', [
                    $this->text($entry->number),
                    $this->formatDate($entry->date, $dateFormat),
                    $this->text($entry->documentType),
                    $this->text($entry->documentNumber),
                    $entry->documentDate === null ? '' : $this->formatDate($entry->documentDate, $dateFormat),
                    $this->text((string) $entry->counterpartName),
                    $this->text((string) $entry->taxId),
                    $this->text($line->accountCode),
                    $this->text($line->accountName),
                    $this->text($line->description),
                    $this->amount($line->debit),
                    $this->amount($line->credit),
                    $this->text($entry->currency),
                    $this->text((string) $line->vatCode),
                ])."\r\n");
            }
        }
    }

    /**
     * The posted entries of a window, as ledger entries in journal order.
     *
     * @return list<LedgerEntry>
     */
    private function journalEntries(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, string $source, string $currency): array
    {
        $journal = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['posted', 'reversed'])
            ->where('ledger_type', 'statutory')
            ->whereBetween('posting_date', [$from->toDateString(), $to->toDateString()])
            ->when($source !== self::SOURCE_ALL, fn ($query) => $query->whereIn('source_document_type', self::SOURCE_TYPES[$source] ?? []))
            ->orderBy('posting_date')
            ->orderBy('journal_number')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $linesByEntry = $this->linesOf($tenantId, $journal->modelKeys());
        $accounts = $this->accountsOf($tenantId, $linesByEntry->flatten(1)->pluck('account_id')->unique()->values()->all());
        $contacts = $this->partners($linesByEntry->flatten(1)->pluck('partner_id')->filter()->unique()->values()->all());
        $categories = $this->bankLineCategories($journal);

        return $journal->map(function (JournalEntry $entry) use ($linesByEntry, $accounts, $contacts, $categories, $currency): LedgerEntry {
            /** @var Collection<int, JournalLine> $entryLines */
            $entryLines = $linesByEntry->get((string) $entry->id, collect());
            $partnerId = $entryLines->first(fn (JournalLine $line): bool => in_array($line->partner_type, ['customer', 'supplier'], true) && $line->partner_id !== null)?->partner_id;
            $contact = $partnerId === null ? null : $contacts->get($partnerId);

            return new LedgerEntry(
                CarbonImmutable::parse($entry->posting_date)->startOfDay(),
                (string) ($entry->source_document_type ?? 'manual'),
                (string) $entry->id,
                $this->documentType($entry, $categories),
                (string) ($entry->document_number ?? $entry->entry_number),
                CarbonImmutable::parse($entry->document_date)->startOfDay(),
                $this->contactName($contact),
                $this->taxId($contact),
                $currency,
                $entryLines->map(fn (JournalLine $line): LedgerLine => $this->ledgerLine($line, $accounts[(string) $line->account_id] ?? ['code' => '', 'name' => ''], $currency))->values()->all(),
            );
        })->values()->all();
    }

    /**
     * The lines of the entries, in their order, by entry id.
     *
     * @param  array<int, mixed>  $entryIds
     * @return Collection<string, Collection<int, JournalLine>>
     */
    private function linesOf(string $tenantId, array $entryIds): Collection
    {
        if ($entryIds === []) {
            return collect();
        }

        return JournalLine::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('journal_entry_id', $entryIds)
            ->orderBy('line_number')
            ->get()
            ->groupBy(fn (JournalLine $line): string => (string) $line->journal_entry_id)
            ->map(fn ($lines): Collection => collect($lines->all()));
    }

    /**
     * Code and name of the accounts of the chart, by id.
     *
     * @param  array<int, mixed>  $accountIds
     * @return array<string, array{code: string, name: string}>
     */
    private function accountsOf(string $tenantId, array $accountIds): array
    {
        $accounts = [];

        foreach (ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', $accountIds)->get() as $account) {
            $code = (string) ($account->getAttribute('account_code') ?? $account->getAttribute('code'));
            $accounts[(string) $account->id] = ['code' => $code, 'name' => (string) ($account->getAttribute('name') ?? $code)];
        }

        return $accounts;
    }

    /**
     * A journal line in accounting-currency cents, on the side it was booked: the booked amount for a line in the
     * accounting currency, the functional amount of a line in another currency.
     *
     * @param  array{code: string, name: string}  $account
     */
    private function ledgerLine(JournalLine $line, array $account, string $currency): LedgerLine
    {
        $isDebit = bccomp((string) $line->debit_amount, '0', 4) === 1;
        $booked = (string) ($isDebit ? $line->debit_amount : $line->credit_amount);
        $cents = $this->cents($line->currency === $currency ? $booked : (string) $line->amount_in_functional_currency);

        return new LedgerLine(
            $account['code'],
            $account['name'],
            (string) $line->description,
            $isDebit ? $cents : 0,
            $isDebit ? 0 : $cents,
            $line->vat_rate_percent === null ? null : rtrim(rtrim(number_format((float) $line->vat_rate_percent, 2, '.', ''), '0'), '.'),
        );
    }

    /**
     * The document type of an entry: the historical names for documents and payments, the category for a categorised
     * bank line, the source type for the other postings, `reversal` for a reversal and `manual` for a manual entry.
     *
     * @param  Collection<string, string>  $categories
     */
    private function documentType(JournalEntry $entry, Collection $categories): string
    {
        $type = (string) $entry->source_document_type;

        return match (true) {
            $entry->journal_type === 'reversing' => 'reversal',
            $type === BankLineLedgerPosting::SOURCE => (string) ($categories->get((string) $entry->source_document_id) ?? 'bank_line'),
            $type === '' => (string) ($entry->journal_type === 'standard' ? 'manual' : $entry->journal_type),
            default => self::DOCUMENT_TYPES[$type] ?? $type,
        };
    }

    /**
     * The contacts named by the lines of the entries, by id.
     *
     * @param  array<int, mixed>  $ids
     * @return Collection<string, Contact>
     */
    private function partners(array $ids): Collection
    {
        return $ids === [] ? collect() : Contact::query()->whereIn('id', $ids)->get()->keyBy(fn (Contact $contact): string => (string) $contact->id);
    }

    /**
     * The categories of the bank statement lines posted in the window, by line id.
     *
     * @param  Collection<int, JournalEntry>  $journal
     * @return Collection<string, string>
     */
    private function bankLineCategories(Collection $journal): Collection
    {
        $ids = $journal->where('source_document_type', BankLineLedgerPosting::SOURCE)->pluck('source_document_id')->filter()->unique()->values()->all();

        return $ids === [] ? collect() : BankStatementLine::query()->whereIn('id', $ids)->pluck('reconciliation_category', 'id')->map(fn (mixed $category): string => (string) $category);
    }

    private function formatDate(CarbonImmutable $date, string $format): string
    {
        return $date->format($format === self::DATE_EUROPEAN ? 'd/m/Y' : 'Y-m-d');
    }

    private function amount(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '');
    }

    /**
     * A decimal amount in integer cents, rounded half up with bcmath.
     */
    private function cents(string $amount): int
    {
        $scaled = bcmul($amount, '100', 4);

        return (int) bcadd($scaled, str_starts_with($scaled, '-') ? '-0.5' : '0.5', 0);
    }

    /**
     * A text cell: quoted when it holds a delimiter, quote or line break, and neutralised when a spreadsheet could
     * read it as a formula.
     */
    private function text(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $value = "'".$value;
        }

        return preg_match('/[;"\r\n]/', $value) === 1 ? '"'.str_replace('"', '""', $value).'"' : $value;
    }

    private function contactName(?Contact $contact): ?string
    {
        if ($contact === null) {
            return null;
        }

        return $contact->company_name ?: (trim($contact->first_name.' '.$contact->last_name) ?: null);
    }

    private function taxId(?Contact $contact): ?string
    {
        return $contact?->vat_number ?: ($contact?->fiscal_code ?: null);
    }
}
