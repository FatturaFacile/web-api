<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\UsageAggregation;

class UsageAggregationFactory extends Factory
{
    protected $model = UsageAggregation::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'subscription_item_id' => SubscriptionItem::factory(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'total_consumed' => 15000.0,
            'included_allowance' => 10000.0,
            'billable_overage' => 5000.0,
            'unit_price' => 0.005,
            'billable_amount' => 25.00,
            'is_billed' => false,
        ];
    }
}
