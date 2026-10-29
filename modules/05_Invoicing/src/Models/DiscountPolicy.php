<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(DiscountPolicyRule::class, 'discount_policy_id');
    }
}
