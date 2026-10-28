<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DiscountPolicyRule extends BaseEnterpriseModel
{
    protected $table = 'discount_policy_rules';

    protected function casts(): array
    {
        return [
            'min_threshold' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'discount_fixed_amount' => 'decimal:2',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(DiscountPolicy::class, 'discount_policy_id');
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }
}
