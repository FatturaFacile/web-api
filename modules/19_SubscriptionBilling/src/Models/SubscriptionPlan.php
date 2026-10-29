<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'is_active' => 'boolean',
            'translations' => 'array',
            'metadata' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SubscriptionProduct::class, 'product_id');
    }

    public function pricingTiers(): HasMany
    {
        return $this->hasMany(PricingTier::class, 'plan_id')->orderBy('tier_number');
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class, 'plan_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}
