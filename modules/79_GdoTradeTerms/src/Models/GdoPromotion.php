<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Domain\Enums\ContributionMode;
use Modules\GdoTradeTerms\Domain\Enums\DateBasis;
use Modules\GdoTradeTerms\Domain\Enums\FixedAccrual;
use Modules\GdoTradeTerms\Domain\Enums\PromotionStatus;
use Modules\GdoTradeTerms\Domain\Enums\PromotionType;

/**
 * A trade promotion or contribution of a contract: flyer (sell-in), sell-out, new store opening, listing fee, preferential
 * display or Ce.Di. logistics, valid in a window filtered by order or delivery date.
 *
 * @property string $contract_id
 * @property string $code
 * @property string $name
 * @property PromotionType $promotion_type
 * @property DateBasis $date_basis
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property ContributionMode $contribution_mode
 * @property string|null $rate_percent
 * @property string|null $unit_amount
 * @property string|null $fixed_amount
 * @property FixedAccrual $fixed_accrual
 * @property string|null $cap_amount
 * @property list<string>|null $category_ids
 * @property list<string>|null $brand_ids
 * @property list<string>|null $product_ids
 * @property list<string>|null $delivery_point_ids
 * @property PromotionStatus $status
 * @property string|null $notes
 */
final class GdoPromotion extends BaseEnterpriseModel
{
    protected $table = 'gdt_promotions';

    protected function casts(): array
    {
        return [
            'promotion_type' => PromotionType::class,
            'date_basis' => DateBasis::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'contribution_mode' => ContributionMode::class,
            'rate_percent' => 'decimal:4',
            'unit_amount' => 'decimal:4',
            'fixed_amount' => 'decimal:4',
            'fixed_accrual' => FixedAccrual::class,
            'cap_amount' => 'decimal:4',
            'category_ids' => 'array',
            'brand_ids' => 'array',
            'product_ids' => 'array',
            'delivery_point_ids' => 'array',
            'status' => PromotionStatus::class,
        ];
    }

    /**
     * @return BelongsTo<GdoContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(GdoContract::class, 'contract_id');
    }
}
