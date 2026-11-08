<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A discount policy or promotion: a header discount (percentage or fixed amount per unit), a validity period, limits
 * (customer, minimum quantity, minimum order amount), a priority, whether it stacks with others and whether it applies
 * by itself; its rules target products, customers or customer groups. Applied by SalesPriceEngine.
 */
final class DiscountPolicy extends BaseEnterpriseModel
{
    protected $table = 'discount_policies';

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'min_order_amount' => 'decimal:2',
            'min_quantity' => 'decimal:4',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'is_stackable' => 'boolean',
            'auto_apply' => 'boolean',
        ];
    }

    /**
     * @return HasMany<DiscountPolicyRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(DiscountPolicyRule::class, 'discount_policy_id');
    }
}
