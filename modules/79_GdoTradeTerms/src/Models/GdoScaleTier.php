<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bracket of a rebate scale: the threshold of the qualifying volume (value or quantity), the rate and an optional flat amount.
 *
 * @property string $scale_id
 * @property int $position
 * @property string $threshold
 * @property string $rate_percent
 * @property string|null $flat_amount
 * @property string|null $label
 */
final class GdoScaleTier extends BaseEnterpriseModel
{
    protected $table = 'gdt_scale_tiers';

    protected function casts(): array
    {
        return ['position' => 'integer', 'threshold' => 'decimal:4', 'rate_percent' => 'decimal:4', 'flat_amount' => 'decimal:4'];
    }

    /**
     * @return BelongsTo<GdoScale, $this>
     */
    public function scale(): BelongsTo
    {
        return $this->belongsTo(GdoScale::class, 'scale_id');
    }
}
