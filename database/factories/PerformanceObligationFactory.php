<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\PerformanceObligation;
use Modules\SubscriptionBilling\Models\RevenueContract;

class PerformanceObligationFactory extends Factory
{
    protected $model = PerformanceObligation::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'revenue_contract_id' => RevenueContract::factory(),
            'pob_code' => 'POB-1-SAAS',
            'description' => 'SaaS Cloud License Obligation',
            'standalone_selling_price' => 1440.00,
            'allocated_transaction_price' => 1440.00,
            'recognition_method' => 'straight_line_over_time',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->subDay()->toDateString(),
            'recognized_revenue_to_date' => 0.00,
            'deferred_revenue_balance' => 1440.00,
            'status' => 'in_progress',
        ];
    }
}
