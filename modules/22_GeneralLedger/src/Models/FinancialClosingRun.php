<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FinancialClosingRun extends BaseEnterpriseModel
{
    protected $table = 'financial_closing_runs';

    protected function casts(): array
    {
        return [
            'net_income_amount' => 'decimal:4',
            'executed_at' => 'datetime',
        ];
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function closingJournal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'closing_journal_id');
    }

    public function retainedEarningsJournal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'retained_earnings_journal_id');
    }
}
