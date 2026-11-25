<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Domain\Enums\BaselineSource;
use Modules\GdoTradeTerms\Domain\Enums\RebateBasis;
use Modules\GdoTradeTerms\Domain\Enums\TierMode;
use Modules\GdoTradeTerms\Domain\Enums\VolumeMode;

/**
 * A tiered year-end rebate scale (PFA / Jahresrückvergütung / Umsatzbonus) of a contract, with the product, brand and
 * delivery-point scope it counts.
 *
 * @property string $contract_id
 * @property string $code
 * @property string $name
 * @property TierMode $tier_mode
 * @property RebateBasis $basis
 * @property VolumeMode $volume_mode
 * @property BaselineSource $baseline_source
 * @property string $baseline_value
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property list<string>|null $category_ids
 * @property list<string>|null $brand_ids
 * @property list<string>|null $product_ids
 * @property list<string>|null $delivery_point_ids
 * @property bool $is_active
 * @property string|null $notes
 */
final class GdoScale extends BaseEnterpriseModel
{
    protected $table = 'gdt_scales';

    protected function casts(): array
    {
        return [
            'tier_mode' => TierMode::class,
            'basis' => RebateBasis::class,
            'volume_mode' => VolumeMode::class,
            'baseline_source' => BaselineSource::class,
            'baseline_value' => 'decimal:4',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'category_ids' => 'array',
            'brand_ids' => 'array',
            'product_ids' => 'array',
            'delivery_point_ids' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<GdoContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(GdoContract::class, 'contract_id');
    }

    /**
     * @return HasMany<GdoScaleTier, $this>
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(GdoScaleTier::class, 'scale_id')->orderBy('threshold');
    }

    /**
     * @return list<array{threshold: string, rate_percent: string, flat_amount: string|null}>
     */
    public function tierRows(): array
    {
        return $this->tiers->map(static fn (GdoScaleTier $tier): array => [
            'threshold' => (string) $tier->threshold,
            'rate_percent' => (string) $tier->rate_percent,
            'flat_amount' => $tier->flat_amount === null ? null : (string) $tier->flat_amount,
        ])->values()->all();
    }

    public function windowStart(GdoContract $contract): Carbon
    {
        return $this->starts_on ?? $contract->starts_on;
    }

    public function windowEnd(GdoContract $contract): Carbon
    {
        return $this->ends_on ?? $contract->ends_on;
    }
}
