<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Company budget of a fiscal year, by ledger account and cost centre. Project budgets are a separate concept.
 */
final class LedgerBudget extends BaseEnterpriseModel
{
    protected $table = 'gl_budgets';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'approved_at' => 'datetime',
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
     * @return HasMany<LedgerBudgetLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(LedgerBudgetLine::class, 'budget_id');
    }
}
