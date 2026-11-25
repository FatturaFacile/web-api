<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\GdoTradeTerms\Domain\Enums\LineStatus;
use Modules\GdoTradeTerms\Domain\Enums\SourceType;

/**
 * What a rebate scale or a promotion added to the accrued provision in a run: the cumulative earned amount minus what the
 * earlier runs had already accrued.
 *
 * @property string $run_id
 * @property string $contract_id
 * @property SourceType $source_type
 * @property string $source_id
 * @property string|null $source_label
 * @property string $currency
 * @property string $base_value
 * @property string $earned_cumulative
 * @property string $previously_accrued
 * @property string $delta_amount
 * @property string|null $applied_rate_percent
 * @property LineStatus $status
 * @property string|null $settlement_line_id
 */
final class GdoAccrualLine extends BaseEnterpriseModel
{
    protected $table = 'gdt_accrual_lines';

    protected function casts(): array
    {
        return [
            'source_type' => SourceType::class,
            'base_value' => 'decimal:4',
            'earned_cumulative' => 'decimal:4',
            'previously_accrued' => 'decimal:4',
            'delta_amount' => 'decimal:4',
            'applied_rate_percent' => 'decimal:4',
            'status' => LineStatus::class,
        ];
    }

    /**
     * @return BelongsTo<GdoAccrualRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(GdoAccrualRun::class, 'run_id');
    }

    /**
     * @return BelongsTo<GdoContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(GdoContract::class, 'contract_id');
    }
}
