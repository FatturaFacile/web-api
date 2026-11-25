<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\GdoTradeTerms\Domain\Enums\SettlementLineStatus;
use Modules\GdoTradeTerms\Domain\Enums\SourceType;
use Modules\GdoTradeTerms\Domain\Enums\Treatment;

/**
 * The settlement of one rebate scale or promotion: accrued, earned and the variance between them.
 *
 * @property string $settlement_id
 * @property SourceType $source_type
 * @property string $source_id
 * @property string|null $source_label
 * @property Treatment $treatment
 * @property string $base_value
 * @property string $accrued_amount
 * @property string $earned_amount
 * @property string $variance_amount
 * @property string $cumulative_earned
 * @property SettlementLineStatus $status
 * @property string|null $notes
 */
final class GdoSettlementLine extends BaseEnterpriseModel
{
    protected $table = 'gdt_settlement_lines';

    protected function casts(): array
    {
        return [
            'source_type' => SourceType::class,
            'treatment' => Treatment::class,
            'base_value' => 'decimal:4',
            'accrued_amount' => 'decimal:4',
            'earned_amount' => 'decimal:4',
            'variance_amount' => 'decimal:4',
            'cumulative_earned' => 'decimal:4',
            'status' => SettlementLineStatus::class,
        ];
    }

    /**
     * @return BelongsTo<GdoSettlement, $this>
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(GdoSettlement::class, 'settlement_id');
    }
}
