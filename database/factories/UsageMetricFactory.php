<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\UsageMetric;

class UsageMetricFactory extends Factory
{
    protected $model = UsageMetric::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'metric_code' => 'API_REQUESTS',
            'name' => 'API Invocations',
            'aggregation_type' => 'sum',
            'unit_of_measure' => 'api_call',
            'is_active' => true,
        ];
    }
}
