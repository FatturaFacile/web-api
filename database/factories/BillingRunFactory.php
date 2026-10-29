<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\BillingRun;

class BillingRunFactory extends Factory
{
    protected $model = BillingRun::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'run_number' => 'RUN-'.date('Ymd').'-'.strtoupper(Str::random(6)),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'execution_date' => now()->toDateString(),
            'total_subscriptions_evaluated' => 50,
            'successful_invoices_count' => 49,
            'failed_invoices_count' => 1,
            'total_invoiced_amount' => 12500.00,
            'status' => 'completed',
        ];
    }
}
