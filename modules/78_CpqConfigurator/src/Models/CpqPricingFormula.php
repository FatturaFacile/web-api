<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A pricing component, applied in `sort_order` while its condition holds. The formula can read the configuration plus
 * `cost_subtotal` (base cost, BOM and routing), `list_subtotal` (the list price built so far) and `quantity`.
 * Effects: `add_cost` adds to the industrial cost, `add_price` adds to the list price, `set_price` replaces it,
 * `multiply_price` multiplies it, `discount_percent` lowers the selling price by that percentage.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $model_id
 * @property string $code
 * @property array<string, string> $label
 * @property string $effect
 * @property string|null $condition_expression
 * @property string $formula
 * @property int $sort_order
 * @property bool $is_active
 */
final class CpqPricingFormula extends BaseEnterpriseModel
{
    public const string AddCost = 'add_cost';

    public const string AddPrice = 'add_price';

    public const string SetPrice = 'set_price';

    public const string MultiplyPrice = 'multiply_price';

    public const string DiscountPercent = 'discount_percent';

    /** @var list<string> */
    public const array Effects = [self::AddCost, self::AddPrice, self::SetPrice, self::MultiplyPrice, self::DiscountPercent];

    protected $table = 'cpq_pricing_formulas';

    protected function casts(): array
    {
        return [
            'label' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
