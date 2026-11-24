<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use App\Models\InvoiceItem;
use App\Models\PassiveInvoiceLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\GeneralLedger\Enums\PostingAccountRole;
use Modules\GeneralLedger\Models\Accrual;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;

/**
 * Aggregates all multi-period transitory items (sales invoice items, passive invoice lines, manual journal lines
 * carrying competence dates, plus standalone gl_accruals) for a target fiscal year and joins them with their
 * 31/12 closing and 01/01 reversal journal entries.
 */
final readonly class AccrualScheduleService
{
    public function __construct(
        private AccrualCalculator $calculator,
        private PostingAccountResolver $postingAccounts,
    ) {}

    /**
     * Resolves the target fiscal window from fiscal_year_id, year integer, or current calendar year.
     *
     * @return array{fiscal_year: ?FiscalYear, year_label: string, start: CarbonImmutable, end: CarbonImmutable}
     */
    public function resolveFiscalWindow(string $tenantId, ?string $fiscalYearId = null, ?int $year = null): array
    {
        $fiscalYear = null;

        if ($fiscalYearId !== null && $fiscalYearId !== '') {
            $fiscalYear = FiscalYear::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($fiscalYearId)
                ->firstOrFail();
        } elseif ($year !== null) {
            $fiscalYear = FiscalYear::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('year_code', (string) $year)
                ->first();
        } else {
            $currentYear = (int) now()->year;
            $fiscalYear = FiscalYear::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('year_code', (string) $currentYear)
                ->first()
                ?? FiscalYear::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->orderByDesc('start_date')
                    ->first();
        }

        if ($fiscalYear !== null) {
            $start = CarbonImmutable::parse($fiscalYear->start_date)->startOfDay();
            $end = CarbonImmutable::parse($fiscalYear->end_date)->startOfDay();

            return [
                'fiscal_year' => $fiscalYear,
                'year_label' => (string) $fiscalYear->year_code,
                'start' => $start,
                'end' => $end,
            ];
        }

        $targetYear = $year ?? (int) now()->year;

        return [
            'fiscal_year' => null,
            'year_label' => (string) $targetYear,
            'start' => CarbonImmutable::create($targetYear, 1, 1)->startOfDay(),
            'end' => CarbonImmutable::create($targetYear, 12, 31)->startOfDay(),
        ];
    }

    /**
     * Builds the full year-end schedule, KPI totals, and filtered item list.
     *
     * @param  array{fiscal_year_id?: ?string, year?: ?int, accrual_type?: ?string, account_id?: ?string, source_type?: ?string, status?: ?string, competence_from?: ?string, competence_to?: ?string, search?: ?string}  $filters
     * @return array{
     *   fiscal_year: ?array{id: string, year_code: string, start_date: string, end_date: string, status: string},
     *   year_label: string,
     *   cutoff_date: string,
     *   reversal_date: string,
     *   currency: string,
     *   kpis: array{
     *     prepaid_expense_total: string,
     *     deferred_revenue_total: string,
     *     accrued_revenue_total: string,
     *     accrued_expense_total: string,
     *     total_recognized: string,
     *     total_transitory: string,
     *     pending_closing_count: int,
     *     closing_posted_count: int,
     *     pending_reversal_count: int,
     *     reversal_posted_count: int,
     *   },
     *   items: list<array<string, mixed>>
     * }
     */
    public function summary(string $tenantId, array $filters = []): array
    {
        $window = $this->resolveFiscalWindow(
            $tenantId,
            isset($filters['fiscal_year_id']) ? (string) $filters['fiscal_year_id'] : null,
            isset($filters['year']) ? (int) $filters['year'] : null,
        );

        $fy = $window['fiscal_year'];
        $fyStart = $window['start'];
        $fyEnd = $window['end'];
        $cutoffDate = $fyEnd->toDateString();
        $reversalDate = $fyEnd->addDay()->toDateString();
        $accountingCurrency = TenantAccountingCurrency::of($tenantId);

        $accountsById = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get(['id', 'account_code', 'name', 'account_type'])
            ->keyBy(static fn (ChartOfAccount $account): string => (string) $account->id);

        $defaultIncomeRevenue = $this->postingAccounts->account($tenantId, PostingAccountRole::SalesRevenue);
        $defaultIncomeExpense = $this->postingAccounts->account($tenantId, PostingAccountRole::PurchaseExpense);
        $defaultBalanceAccounts = [
            'prepaid_expense' => $this->postingAccounts->account($tenantId, PostingAccountRole::PrepaidExpense),
            'deferred_revenue' => $this->postingAccounts->account($tenantId, PostingAccountRole::DeferredRevenue),
            'accrued_revenue' => $this->postingAccounts->account($tenantId, PostingAccountRole::AccruedRevenue),
            'accrued_expense' => $this->postingAccounts->account($tenantId, PostingAccountRole::AccruedExpense),
        ];

        $accruals = Accrual::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->with(['journalEntry', 'reversalJournalEntry'])
            ->orderByDesc('cutoff_date')
            ->orderByDesc('created_at')
            ->get();

        $accrualsBySource = [];
        $manualAccruals = [];

        foreach ($accruals as $accrual) {
            $sourceType = (string) ($accrual->getAttribute('source_type') ?? 'manual');
            $sourceLineId = $accrual->getAttribute('source_line_id');
            $accrualCutoff = $accrual->cutoff_date->toDateString();

            if ($sourceType !== 'manual' && $sourceLineId !== null && $accrualCutoff === $cutoffDate) {
                $accrualsBySource[$sourceType.':'.$sourceLineId] = $accrual;
            } elseif ($sourceType === 'manual' && $accrualCutoff >= $fyStart->toDateString() && $accrualCutoff <= $cutoffDate) {
                $manualAccruals[] = $accrual;
            }
        }

        $items = [];

        // 1. Sales invoice lines with competence dates
        $salesLines = InvoiceItem::query()
            ->where('invoice_items.tenant_id', $tenantId)
            ->whereNull('invoice_items.deleted_at')
            ->whereNotNull('invoice_items.competence_start_date')
            ->whereNotNull('invoice_items.competence_end_date')
            ->whereHas('invoice', static function ($query) use ($tenantId): void {
                $query->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->where('invoice_type', '!=', 'proforma')
                    ->whereIn('status', ['issued', 'posted', 'paid', 'approved']);
            })
            ->with(['invoice' => static fn ($q) => $q->withoutGlobalScopes()])
            ->get();

        foreach ($salesLines as $line) {
            $invoice = $line->invoice;
            if ($invoice === null) {
                continue;
            }
            $isCreditNote = $invoice->invoice_type === 'credit_note' || $invoice->document_type === 'TD04';
            $direction = $isCreditNote ? 'cost' : 'revenue';
            $rate = Decimal::isPositive((string) $invoice->exchange_rate)
                ? (string) $invoice->exchange_rate
                : '1';
            $lineNet = Decimal::round2(Decimal::mul((string) abs((float) $line->line_total), $rate));

            if (! Decimal::isPositive($lineNet)) {
                continue;
            }

            $calc = $this->calculator->calculate(
                totalAmount: $lineNet,
                start: CarbonImmutable::parse($line->competence_start_date),
                end: CarbonImmutable::parse($line->competence_end_date),
                fyStart: $fyStart,
                fyEnd: $fyEnd,
                documentDate: CarbonImmutable::parse($invoice->date),
                direction: $direction,
            );

            if ($calc['accrual_type'] === null || ! Decimal::isPositive($calc['transitory_amount'])) {
                continue;
            }

            $incomeAccount = $line->revenue_account_id !== null
                ? $accountsById->get((string) $line->revenue_account_id)
                : ($direction === 'revenue' ? $defaultIncomeRevenue : $defaultIncomeExpense);

            $items[] = $this->formatRow(
                sourceType: 'sales_invoice_line',
                sourceDocumentType: 'sales_invoice',
                sourceDocumentId: (string) $invoice->id,
                sourceLineId: (string) $line->id,
                sourceDocumentNumber: (string) $invoice->invoice_number,
                documentDate: $invoice->date->toDateString(),
                description: (string) $line->description,
                currency: $accountingCurrency,
                calc: $calc,
                incomeAccount: $incomeAccount,
                defaultBalanceAccount: $defaultBalanceAccounts[$calc['accrual_type']] ?? null,
                costCenterId: null,
                persisted: $accrualsBySource['sales_invoice_line:'.$line->id] ?? null,
                accountsById: $accountsById,
            );
        }

        // 2. Passive invoice lines (supplier bills) with competence dates
        $passiveLines = PassiveInvoiceLine::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereNotNull('competence_start_date')
            ->whereNotNull('competence_end_date')
            ->whereHas('invoice', static function ($query) use ($tenantId): void {
                $query->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at');
            })
            ->with(['invoice' => static fn ($q) => $q->withoutGlobalScopes()])
            ->get();

        foreach ($passiveLines as $line) {
            $invoice = $line->invoice;
            if ($invoice === null) {
                continue;
            }
            $isCreditNote = $invoice->document_type === 'TD04';
            $direction = $isCreditNote ? 'revenue' : 'cost';
            $rate = $invoice->exchange_rate !== null && Decimal::isPositive((string) $invoice->exchange_rate)
                ? (string) $invoice->exchange_rate
                : '1';
            $lineNet = Decimal::round2(Decimal::mul((string) abs((float) $line->line_total), $rate));

            if (! Decimal::isPositive($lineNet)) {
                continue;
            }

            $calc = $this->calculator->calculate(
                totalAmount: $lineNet,
                start: CarbonImmutable::parse($line->competence_start_date),
                end: CarbonImmutable::parse($line->competence_end_date),
                fyStart: $fyStart,
                fyEnd: $fyEnd,
                documentDate: CarbonImmutable::parse($invoice->date),
                direction: $direction,
            );

            if ($calc['accrual_type'] === null || ! Decimal::isPositive($calc['transitory_amount'])) {
                continue;
            }

            $accountId = $line->cost_account_id ?? $invoice->cost_account_id;
            $incomeAccount = $accountId !== null
                ? $accountsById->get((string) $accountId)
                : ($direction === 'cost' ? $defaultIncomeExpense : $defaultIncomeRevenue);

            $items[] = $this->formatRow(
                sourceType: 'passive_invoice_line',
                sourceDocumentType: 'passive_invoice',
                sourceDocumentId: (string) $invoice->id,
                sourceLineId: (string) $line->id,
                sourceDocumentNumber: (string) $invoice->invoice_number,
                documentDate: $invoice->date->toDateString(),
                description: (string) $line->description,
                currency: $accountingCurrency,
                calc: $calc,
                incomeAccount: $incomeAccount,
                defaultBalanceAccount: $defaultBalanceAccounts[$calc['accrual_type']] ?? null,
                costCenterId: $invoice->cost_center_id !== null ? (string) $invoice->cost_center_id : null,
                persisted: $accrualsBySource['passive_invoice_line:'.$line->id] ?? null,
                accountsById: $accountsById,
            );
        }

        // 3. Posted manual / prima nota journal lines with competence dates
        $journalLines = JournalLine::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereNotNull('competence_start_date')
            ->whereNotNull('competence_end_date')
            ->whereHas('journalEntry', static function ($query) use ($tenantId): void {
                $query->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->where('status', 'posted')
                    ->whereNotIn('journal_type', ['closing', 'reversing', 'adjusting'])
                    ->where(static function ($sq): void {
                        $sq->whereNull('source_document_type')
                            ->orWhereNotIn('source_document_type', ['sales_invoice', 'credit_note', 'vendor_invoice', 'vendor_credit_note', 'gl_accrual']);
                    });
            })
            ->with(['journalEntry' => static fn ($q) => $q->withoutGlobalScopes()])
            ->get();

        foreach ($journalLines as $line) {
            $entry = $line->journalEntry;
            if ($entry === null) {
                continue;
            }
            $account = $accountsById->get((string) $line->account_id);
            if ($account === null || ! in_array($account->account_type, ['revenue', 'expense', 'cogs'], true)) {
                continue;
            }

            $debit = (string) ($line->functional_debit ?? $line->debit_amount);
            $credit = (string) ($line->functional_credit ?? $line->credit_amount);
            $isDebit = Decimal::isPositive($debit);
            $amount = Decimal::round2($isDebit ? $debit : $credit);

            if (! Decimal::isPositive($amount)) {
                continue;
            }

            $direction = $account->account_type === 'revenue' ? 'revenue' : 'cost';

            $calc = $this->calculator->calculate(
                totalAmount: $amount,
                start: CarbonImmutable::parse($line->competence_start_date),
                end: CarbonImmutable::parse($line->competence_end_date),
                fyStart: $fyStart,
                fyEnd: $fyEnd,
                documentDate: CarbonImmutable::parse($entry->posting_date ?? $cutoffDate),
                direction: $direction,
            );

            if ($calc['accrual_type'] === null || ! Decimal::isPositive($calc['transitory_amount'])) {
                continue;
            }

            $items[] = $this->formatRow(
                sourceType: 'journal_line',
                sourceDocumentType: 'journal_entry',
                sourceDocumentId: (string) $entry->id,
                sourceLineId: (string) $line->id,
                sourceDocumentNumber: (string) $entry->entry_number,
                documentDate: $entry->posting_date?->toDateString() ?? $cutoffDate,
                description: (string) ($line->description !== '' ? $line->description : $entry->description),
                currency: $accountingCurrency,
                calc: $calc,
                incomeAccount: $account,
                defaultBalanceAccount: $defaultBalanceAccounts[$calc['accrual_type']] ?? null,
                costCenterId: $line->cost_center_id !== null ? (string) $line->cost_center_id : null,
                persisted: $accrualsBySource['journal_line:'.$line->id] ?? null,
                accountsById: $accountsById,
            );
        }

        // 4. Standalone / manual gl_accruals created via POST /general-ledger/accruals
        foreach ($manualAccruals as $accrual) {
            $start = CarbonImmutable::parse($accrual->coverage_start);
            $end = CarbonImmutable::parse($accrual->coverage_end);
            $cutoff = CarbonImmutable::parse($accrual->cutoff_date);
            $totalAmount = Decimal::round2((string) $accrual->total_amount);
            $transitoryAmount = Decimal::round2((string) $accrual->amount);
            $periodDays = $accrual->getAttribute('period_days') !== null
                ? (int) $accrual->getAttribute('period_days')
                : $this->calculator->overlapDays($start, $end, $fyStart, $cutoff);
            $recognizedAmount = $accrual->getAttribute('recognized_amount') !== null
                ? Decimal::round2((string) $accrual->getAttribute('recognized_amount'))
                : (in_array($accrual->accrual_type, ['prepaid_expense', 'deferred_revenue'], true)
                    ? Decimal::round2(Decimal::sub($totalAmount, $transitoryAmount))
                    : $transitoryAmount);

            $calc = [
                'accrual_type' => $accrual->accrual_type,
                'total_amount' => $totalAmount,
                'coverage_start' => $start->toDateString(),
                'coverage_end' => $end->toDateString(),
                'cutoff_date' => $cutoff->toDateString(),
                'reversal_date' => $accrual->reversal_date?->toDateString() ?? $cutoff->addDay()->toDateString(),
                'total_days' => (int) $accrual->coverage_days,
                'period_days' => $periodDays,
                'transitory_days' => (int) $accrual->accrued_days,
                'recognized_amount' => $recognizedAmount,
                'transitory_amount' => $transitoryAmount,
                'yearly_schedule' => [],
            ];

            $items[] = $this->formatRow(
                sourceType: 'manual',
                sourceDocumentType: null,
                sourceDocumentId: null,
                sourceLineId: (string) $accrual->id,
                sourceDocumentNumber: $accrual->getAttribute('source_document_number'),
                documentDate: $cutoff->toDateString(),
                description: (string) $accrual->description,
                currency: (string) ($accrual->getAttribute('currency') ?? $accountingCurrency),
                calc: $calc,
                incomeAccount: $accountsById->get((string) $accrual->income_statement_account_id),
                defaultBalanceAccount: $accountsById->get((string) $accrual->balance_sheet_account_id),
                costCenterId: $accrual->cost_center_id !== null ? (string) $accrual->cost_center_id : null,
                persisted: $accrual,
                accountsById: $accountsById,
            );
        }

        // Compute KPIs across all items for the fiscal year before row-level filtering (or after? Summary KPIs reflect the year, filtered list reflects filters; wait: let's keep KPIs reflecting the filtered set when filters are active, or full year? Usually KPIs match filtered view or full schedule — let's apply filters first or compute KPIs over filtered items? Wait: if someone filters by "Risconti Attivi", KPI cards for the other 3 types shouldn't show 0 if we want KPI cards as an overview, OR if we compute KPIs over the full year before type/status filter, the cards stay useful while switching filter tabs! Let's compute KPIs over all discovered items of the fiscal year!)
        $kpis = $this->computeKpis($items);
        $filtered = $this->applyFilters($items, $filters);

        return [
            'fiscal_year' => $fy !== null ? [
                'id' => (string) $fy->id,
                'year_code' => (string) $fy->year_code,
                'start_date' => $fyStart->toDateString(),
                'end_date' => $fyEnd->toDateString(),
                'status' => (string) $fy->status,
            ] : null,
            'year_label' => $window['year_label'],
            'cutoff_date' => $cutoffDate,
            'reversal_date' => $reversalDate,
            'currency' => $accountingCurrency,
            'kpis' => $kpis,
            'items' => $filtered,
        ];
    }

    /**
     * @param  array<string, mixed>  $calc
     * @param  Collection<string, ChartOfAccount>  $accountsById
     * @return array<string, mixed>
     */
    private function formatRow(
        string $sourceType,
        ?string $sourceDocumentType,
        ?string $sourceDocumentId,
        string $sourceLineId,
        ?string $sourceDocumentNumber,
        ?string $documentDate,
        string $description,
        string $currency,
        array $calc,
        ?ChartOfAccount $incomeAccount,
        ?ChartOfAccount $defaultBalanceAccount,
        ?string $costCenterId,
        ?Accrual $persisted,
        Collection $accountsById,
    ): array {
        $closingEntry = $persisted?->journalEntry;
        $reversalEntry = $persisted?->reversalJournalEntry;

        if ($reversalEntry === null && $closingEntry !== null && $closingEntry->status === 'reversed' && $closingEntry->reversal_journal_entry_id !== null) {
            $reversalEntry = JournalEntry::withoutGlobalScopes()->find($closingEntry->reversal_journal_entry_id);
        }

        $balanceAccount = $persisted !== null
            ? $accountsById->get((string) $persisted->balance_sheet_account_id)
            : $defaultBalanceAccount;

        $resolvedIncomeAccount = $persisted !== null
            ? ($accountsById->get((string) $persisted->income_statement_account_id) ?? $incomeAccount)
            : $incomeAccount;

        $status = 'calculated';
        if ($reversalEntry !== null || ($closingEntry !== null && $closingEntry->status === 'reversed')) {
            $status = 'reversal_posted';
        } elseif ($persisted !== null && $persisted->journal_entry_id !== null) {
            $status = 'closing_posted';
        }

        return [
            'item_key' => $sourceType.':'.$sourceLineId,
            'accrual_id' => $persisted !== null ? (string) $persisted->id : null,
            'accrual_type' => $calc['accrual_type'],
            'source_type' => $sourceType,
            'source_document_type' => $sourceDocumentType,
            'source_document_id' => $sourceDocumentId,
            'source_line_id' => $sourceLineId,
            'source_document_number' => $sourceDocumentNumber,
            'document_date' => $documentDate,
            'description' => $description,
            'currency' => $currency,
            'competence_start_date' => $calc['coverage_start'],
            'competence_end_date' => $calc['coverage_end'],
            'cutoff_date' => $calc['cutoff_date'],
            'reversal_date' => $calc['reversal_date'],
            'total_days' => $calc['total_days'],
            'period_days' => $calc['period_days'],
            'transitory_days' => $calc['transitory_days'],
            'total_amount' => $calc['total_amount'],
            'recognized_amount' => $calc['recognized_amount'],
            'transitory_amount' => $calc['transitory_amount'],
            'income_statement_account_id' => $resolvedIncomeAccount?->id ? (string) $resolvedIncomeAccount->id : null,
            'income_statement_account_code' => $resolvedIncomeAccount?->account_code,
            'income_statement_account_name' => $resolvedIncomeAccount?->name,
            'balance_sheet_account_id' => $balanceAccount?->id ? (string) $balanceAccount->id : null,
            'balance_sheet_account_code' => $balanceAccount?->account_code,
            'balance_sheet_account_name' => $balanceAccount?->name,
            'cost_center_id' => $costCenterId,
            'status' => $status,
            'closing_journal_entry_id' => $closingEntry !== null ? (string) $closingEntry->id : ($persisted?->journal_entry_id ? (string) $persisted->journal_entry_id : null),
            'closing_journal_entry_number' => $closingEntry?->entry_number,
            'closing_journal_posting_date' => $closingEntry?->posting_date?->toDateString(),
            'closing_journal_status' => $closingEntry?->status,
            'reversal_journal_entry_id' => $reversalEntry !== null ? (string) $reversalEntry->id : null,
            'reversal_journal_entry_number' => $reversalEntry?->entry_number,
            'reversal_journal_posting_date' => $reversalEntry?->posting_date?->toDateString(),
            'yearly_schedule' => $calc['yearly_schedule'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{
     *   prepaid_expense_total: string,
     *   deferred_revenue_total: string,
     *   accrued_revenue_total: string,
     *   accrued_expense_total: string,
     *   total_recognized: string,
     *   total_transitory: string,
     *   pending_closing_count: int,
     *   closing_posted_count: int,
     *   pending_reversal_count: int,
     *   reversal_posted_count: int,
     * }
     */
    private function computeKpis(array $items): array
    {
        $byType = [
            'prepaid_expense' => '0.00',
            'deferred_revenue' => '0.00',
            'accrued_revenue' => '0.00',
            'accrued_expense' => '0.00',
        ];
        $totalRecognized = '0.00';
        $totalTransitory = '0.00';
        $pendingClosing = 0;
        $closingPosted = 0;
        $pendingReversal = 0;
        $reversalPosted = 0;

        foreach ($items as $item) {
            $type = (string) $item['accrual_type'];
            $transitory = (string) $item['transitory_amount'];
            $recognized = (string) $item['recognized_amount'];

            if (array_key_exists($type, $byType)) {
                $byType[$type] = Decimal::round2(Decimal::add($byType[$type], $transitory));
            }
            $totalRecognized = Decimal::round2(Decimal::add($totalRecognized, $recognized));
            $totalTransitory = Decimal::round2(Decimal::add($totalTransitory, $transitory));

            match ($item['status']) {
                'calculated' => $pendingClosing++,
                'closing_posted' => (function () use (&$closingPosted, &$pendingReversal): void {
                    $closingPosted++;
                    $pendingReversal++;
                })(),
                'reversal_posted' => (function () use (&$closingPosted, &$reversalPosted): void {
                    $closingPosted++;
                    $reversalPosted++;
                })(),
                default => null,
            };
        }

        return [
            'prepaid_expense_total' => $byType['prepaid_expense'],
            'deferred_revenue_total' => $byType['deferred_revenue'],
            'accrued_revenue_total' => $byType['accrued_revenue'],
            'accrued_expense_total' => $byType['accrued_expense'],
            'total_recognized' => $totalRecognized,
            'total_transitory' => $totalTransitory,
            'pending_closing_count' => $pendingClosing,
            'closing_posted_count' => $closingPosted,
            'pending_reversal_count' => $pendingReversal,
            'reversal_posted_count' => $reversalPosted,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function applyFilters(array $items, array $filters): array
    {
        $type = ! empty($filters['accrual_type']) ? (string) $filters['accrual_type'] : null;
        $accountId = ! empty($filters['account_id']) ? (string) $filters['account_id'] : null;
        $sourceType = ! empty($filters['source_type']) ? (string) $filters['source_type'] : null;
        $status = ! empty($filters['status']) ? (string) $filters['status'] : null;
        $from = ! empty($filters['competence_from']) ? (string) $filters['competence_from'] : null;
        $to = ! empty($filters['competence_to']) ? (string) $filters['competence_to'] : null;
        $search = ! empty($filters['search']) ? mb_strtolower(trim((string) $filters['search'])) : null;

        return array_values(array_filter($items, static function (array $item) use ($type, $accountId, $sourceType, $status, $from, $to, $search): bool {
            if ($type !== null && $item['accrual_type'] !== $type) {
                return false;
            }
            if ($sourceType !== null && $item['source_type'] !== $sourceType) {
                return false;
            }
            if ($status !== null && $item['status'] !== $status) {
                return false;
            }
            if ($accountId !== null && $item['income_statement_account_id'] !== $accountId && $item['balance_sheet_account_id'] !== $accountId) {
                return false;
            }
            if ($from !== null && (string) $item['competence_end_date'] < $from) {
                return false;
            }
            if ($to !== null && (string) $item['competence_start_date'] > $to) {
                return false;
            }
            if ($search !== null && $search !== '') {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    (string) ($item['description'] ?? ''),
                    (string) ($item['source_document_number'] ?? ''),
                    (string) ($item['income_statement_account_code'] ?? ''),
                    (string) ($item['income_statement_account_name'] ?? ''),
                    (string) ($item['balance_sheet_account_code'] ?? ''),
                    (string) ($item['balance_sheet_account_name'] ?? ''),
                ])));

                if (! str_contains($haystack, $search)) {
                    return false;
                }
            }

            return true;
        }));
    }
}
