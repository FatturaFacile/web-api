<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An item of a subscription: the plan, an add-on or charge, or a metered item.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $subscription_id
 * @property string|null $plan_id
 * @property string $item_type
 * @property string $description
 * @property string|null $tax_rate
 * @property string|null $metric_id
 * @property string|null $legacy_item_id
 * @property array<string, mixed>|null $metadata
 */
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
            'tax_rate' => 'decimal:2',
            'price_factor' => 'decimal:8',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    /**
     * @return BelongsTo<SubscriptionProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(SubscriptionProduct::class, 'product_id');
    }

    /**
     * @return BelongsTo<UsageMetric, $this>
     */
    public function metric(): BelongsTo
    {
        return $this->belongsTo(UsageMetric::class, 'metric_id');
    }

    /**
     * @return HasMany<UsageAggregation, $this>
     */
    public function usageAggregations(): HasMany
    {
        return $this->hasMany(UsageAggregation::class, 'subscription_item_id');
    }
}
