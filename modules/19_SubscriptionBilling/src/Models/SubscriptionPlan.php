<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A subscription plan: interval, alignment, pricing model, tiers and features.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $product_id
 * @property string $plan_code
 * @property string $name
 * @property string|null $description
 * @property string $billing_interval
 * @property int $interval_count
 * @property string $billing_alignment
 * @property string $pricing_model
 * @property string $currency
 * @property string|null $tax_rate
 * @property int $trial_period_days
 * @property string|null $revenue_account_id
 * @property string|null $deferred_revenue_account_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 */
final class SubscriptionPlan extends BaseEnterpriseModel
{
    protected $table = 'sub_plans';

    protected function casts(): array
    {
        return [
            'interval_count' => 'integer',
            'setup_fee' => 'decimal:2',
            'base_price' => 'decimal:4',
            'trial_period_days' => 'integer',
            'min_commitment_cycles' => 'integer',
            'tax_rate' => 'decimal:2',
            'renewal_price_increase_percent' => 'decimal:4',
            'is_active' => 'boolean',
            'translations' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SubscriptionProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(SubscriptionProduct::class, 'product_id');
    }

    /**
     * @return HasMany<PricingTier, $this>
     */
    public function pricingTiers(): HasMany
    {
        return $this->hasMany(PricingTier::class, 'plan_id')->orderBy('tier_number');
    }

    /**
     * @return HasMany<PlanFeature, $this>
     */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class, 'plan_id');
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}
