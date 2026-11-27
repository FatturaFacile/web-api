<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use App\Models\Concerns\ProtectsPostedJournalLine;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A line of a journal entry: its amounts in the transaction currency (`debit_amount`, `credit_amount`, `currency`,
 * `exchange_rate`) and in the accounting currency of the tenant (`functional_debit`, `functional_credit`), which every
 * balance and report sums. The functional amounts are null only on a historical foreign-currency line posted without
 * a usable rate.
 *
 * @property string|null $functional_debit
 * @property string|null $functional_credit
 * @property Carbon|null $competence_start_date
 * @property Carbon|null $competence_end_date
 */
final class JournalLine extends BaseEnterpriseModel
{
    use ProtectsPostedJournalLine;

    protected $table = 'journal_lines';

    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
            'line_number' => 'integer',
            'debit_amount' => 'decimal:4',
            'credit_amount' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'amount_in_functional_currency' => 'decimal:4',
            'functional_debit' => 'decimal:4',
            'functional_credit' => 'decimal:4',
            'vat_base_amount' => 'decimal:4',
            'vat_tax_amount' => 'decimal:4',
            'vat_rate_percent' => 'decimal:2',
            'is_cleared' => 'boolean',
            'competence_start_date' => 'date',
            'competence_end_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    /**
     * @return BelongsTo<CostCenter, $this>
     */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_id');
    }

    /**
     * @return BelongsTo<ProfitCenter, $this>
     */
    public function profitCenter(): BelongsTo
    {
        return $this->belongsTo(ProfitCenter::class, 'profit_center_id');
    }

    /**
     * @return BelongsTo<VatCode, $this>
     */
    public function vatCode(): BelongsTo
    {
        return $this->belongsTo(VatCode::class, 'vat_code_id');
    }
}
