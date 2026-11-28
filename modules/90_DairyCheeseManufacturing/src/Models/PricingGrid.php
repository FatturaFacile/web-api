<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Support\Carbon;

/**
 * Quality bonus / penalty grid with the base price and the cheese-milk supplements. A null tenant is a shared template.
 *
 * @property string $name
 * @property string $jurisdiction
 * @property string|null $cheese_type
 * @property string $base_price_per_kg
 * @property string $cheese_milk_supplement_per_kg
 * @property string $dop_premium_per_kg
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property bool $is_active
 * @property array<int,array<string,mixed>>|null $tiers
 */
final class PricingGrid extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'dairy_milk_pricing_grids';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_price_per_kg' => 'decimal:4',
            'cheese_milk_supplement_per_kg' => 'decimal:4',
            'dop_premium_per_kg' => 'decimal:4',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
            'tiers' => 'array',
        ];
    }
}
