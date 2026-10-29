<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\ChurnPrediction;
use Modules\SubscriptionBilling\Models\Subscription;

class ChurnPredictionFactory extends Factory
{
    protected $model = ChurnPrediction::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'subscription_id' => Subscription::factory(),
            'churn_probability_score' => 15.00,
            'risk_tier' => 'low',
            'primary_churn_factor' => 'Usage health score stable',
            'ai_mitigation_recommendation' => 'Present annual plan discount upsell',
            'analyzed_at' => now(),
        ];
    }
}
