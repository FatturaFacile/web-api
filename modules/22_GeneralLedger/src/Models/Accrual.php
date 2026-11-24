<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An accrual or deferral (rateo o risconto) at a cut-off date: the share of a cost or revenue that belongs to the
 * period before the cut-off (ratei) or after it (risconti), computed by days of coverage. It is posted at the cut-off
 * date and reversed automatically on the day after. The older `accrual_entries` table was never written and is left
 * untouched.
 *
 * @property Carbon $coverage_start
 * @property Carbon $coverage_end
 * @property Carbon $cutoff_date
 * @property Carbon|null $reversal_date
 */
final class Accrual extends BaseEnterpriseModel
{
    /**
     * accrued_revenue = rateo attivo, accrued_expense = rateo passivo, prepaid_expense = risconto attivo,
     * deferred_revenue = risconto passivo.
     *
     * @var list<string>
     */
    public const array Types = ['accrued_revenue', 'accrued_expense', 'prepaid_expense', 'deferred_revenue'];

    /** @var list<string> */
    public const array SourceTypes = ['manual', 'sales_invoice_line', 'passive_invoice_line', 'journal_line'];

    protected $table = 'gl_accruals';

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:4',
            'amount' => 'decimal:4',
            'recognized_amount' => 'decimal:4',
            'coverage_start' => 'date',
            'coverage_end' => 'date',
            'cutoff_date' => 'date',
            'reversal_date' => 'date',
            'coverage_days' => 'integer',
            'accrued_days' => 'integer',
            'period_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function incomeStatementAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'income_statement_account_id');
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function balanceSheetAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'balance_sheet_account_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    /**
     * True for ratei, whose share is the part of the coverage up to the cut-off.
     */
    public function isAccrual(): bool
    {
        return in_array($this->accrual_type, ['accrued_revenue', 'accrued_expense'], true);
    }
}
