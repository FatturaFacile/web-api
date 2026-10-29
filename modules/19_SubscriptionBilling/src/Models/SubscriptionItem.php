<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SubscriptionItem extends BaseEnterpriseModel
{
    protected $table = 'sub_subscription_items';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_percentage' => 'decimal:2',
            'subtotal_recurring' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SubscriptionProduct::class, 'product_id');
    }

    public function metric(): BelongsTo
    {
        return $this->belongsTo(UsageMetric::class, 'metric_id');
    }

    public function usageAggregations(): HasMany
    {
        return $this->hasMany(UsageAggregation::class, 'subscription_item_id');
    }
}
