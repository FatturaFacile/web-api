<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SubscriptionProduct extends BaseEnterpriseModel
{
    protected $table = 'sub_products';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function plans(): HasMany
    {
        return $this->hasMany(SubscriptionPlan::class, 'product_id');
    }

    public function subscriptionItems(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class, 'product_id');
    }
}
