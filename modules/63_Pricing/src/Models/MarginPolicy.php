<?php

declare(strict_types=1);

namespace Modules\Pricing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Minimum gross margin for a scope (global, category, product or price list).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $scope
 * @property string|null $scope_id
 * @property string $min_margin_percent
 * @property string $cost_basis
 * @property bool $block_on_violation
 * @property string|null $requires_approval_role
 */
final class MarginPolicy extends BaseEnterpriseModel
{
    public const string Global = 'GLOBAL';

    public const string Category = 'CATEGORY';

    public const string Product = 'PRODUCT';

    public const string PriceList = 'PRICE_LIST';

    /** @var list<string> */
    public const array Scopes = [self::Global, self::Category, self::Product, self::PriceList];

    public const string LastPurchaseCost = 'LAST_PURCHASE_COST';

    public const string AverageCost = 'AVERAGE_COST';

    protected $table = 'margin_policies';

    protected function casts(): array
    {
        return [
            'min_margin_percent' => 'decimal:2',
            'block_on_violation' => 'boolean',
        ];
    }
}
