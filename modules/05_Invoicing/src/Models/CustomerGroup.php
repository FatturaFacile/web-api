<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CustomerGroup extends BaseEnterpriseModel
{
    protected $table = 'customer_groups';

    protected function casts(): array
    {
        return [
            'discount_percentage' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(SalesPriceList::class, 'price_list_id');
    }

    public function discountRules(): HasMany
    {
        return $this->hasMany(DiscountPolicyRule::class, 'customer_group_id');
    }
}
