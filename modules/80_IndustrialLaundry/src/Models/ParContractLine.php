<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\IndustrialLaundry\Domain\Enums\ArticleType;

/**
 * The par level and the prices of one article type of a contract.
 *
 * @property string $contract_id
 * @property ArticleType $article_type
 * @property string|null $product_id
 * @property string|null $label
 * @property int $circulating_stock_qty Fixed endowment ("dotazione fissa") in circulation: the par level.
 * @property string $weekly_min_billing_percentage Percent of the par level billed every week at least.
 * @property string|null $seasonal_min_billing_percentage Percent that replaces the weekly one inside the season months.
 * @property int|null $season_start_month
 * @property int|null $season_end_month
 * @property string $unit_wash_price
 * @property string $price_per_kg
 * @property string $rental_price Monthly rental per garment.
 * @property string $replacement_price
 */
final class ParContractLine extends BaseEnterpriseModel
{
    protected $table = 'ilr_par_contract_lines';

    protected function casts(): array
    {
        return [
            'article_type' => ArticleType::class,
            'circulating_stock_qty' => 'integer',
            'weekly_min_billing_percentage' => 'decimal:4',
            'seasonal_min_billing_percentage' => 'decimal:4',
            'season_start_month' => 'integer',
            'season_end_month' => 'integer',
            'unit_wash_price' => 'decimal:4',
            'price_per_kg' => 'decimal:4',
            'rental_price' => 'decimal:4',
            'replacement_price' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<ParContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ParContract::class, 'contract_id');
    }
}
