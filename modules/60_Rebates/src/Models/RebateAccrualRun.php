<?php

declare(strict_types=1);

namespace Modules\Rebates\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A periodic accrual run of a supplier agreement: the period, the qualifying spend, the cumulative rebate, the part
 * accrued by this run, the ledger entry that books it and the invoices that contributed to it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $agreement_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $status
 * @property string $base_amount
 * @property string $cumulative_rebate
 * @property string $delta_amount
 * @property string|null $journal_entry_id
 * @property string|null $reversal_journal_entry_id
 * @property Carbon|null $reversed_at
 * @property string|null $reversal_reason
 * @property string|null $created_by
 */
final class RebateAccrualRun extends BaseEnterpriseModel
{
    public const string Posted = 'posted';

    public const string Reversed = 'reversed';

    protected $table = 'rebate_accrual_runs';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'base_amount' => 'decimal:2',
            'cumulative_rebate' => 'decimal:2',
            'delta_amount' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RebateAgreement, $this>
     */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(RebateAgreement::class, 'agreement_id');
    }

    /**
     * @return HasMany<RebateAccrualLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RebateAccrualLine::class, 'accrual_run_id')->orderBy('invoice_date');
    }

    /**
     * @return HasMany<RebateAccrual, $this>
     */
    public function accruals(): HasMany
    {
        return $this->hasMany(RebateAccrual::class, 'accrual_run_id');
    }
}
