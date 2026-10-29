<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\PlanFeature;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;

class PlanFeatureFactory extends Factory
{
    protected $model = PlanFeature::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'plan_id' => SubscriptionPlan::factory(),
            'feature_code' => 'API_CALLS',
            'name' => 'Monthly API Requests',
            'metric_type' => 'metered_quota',
            'included_quantity' => 10000.0,
            'overage_unit_price' => 0.005,
            'is_active' => true,
        ];
    }
}
