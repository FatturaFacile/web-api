<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\PerformanceObligation;
use Modules\SubscriptionBilling\Models\RevenueSchedule;

class RevenueScheduleFactory extends Factory
{
    protected $model = RevenueSchedule::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'performance_obligation_id' => PerformanceObligation::factory(),
            'period_date' => now()->endOfMonth()->toDateString(),
            'scheduled_amount' => 120.00,
            'recognized_amount' => 0.00,
            'is_recognized' => false,
        ];
    }
}
