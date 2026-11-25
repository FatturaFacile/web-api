<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Domain\Enums\RunStatus;

/**
 * The monthly closing of the accrued rebates and contributions of a company: one live run per month, posted as one journal entry.
 *
 * @property string $period_key
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property RunStatus $status
 * @property string|null $unique_key
 * @property string $total_accrued
 * @property int $contracts_count
 * @property string|null $journal_entry_id
 * @property string|null $reversal_journal_entry_id
 * @property string|null $run_by
 * @property Carbon|null $posted_at
 * @property Carbon|null $reversed_at
 * @property string|null $reversal_reason
 */
final class GdoAccrualRun extends BaseEnterpriseModel
{
    protected $table = 'gdt_accrual_runs';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => RunStatus::class,
            'total_accrued' => 'decimal:4',
            'contracts_count' => 'integer',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<GdoAccrualLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GdoAccrualLine::class, 'run_id')->orderBy('source_label');
    }
}
