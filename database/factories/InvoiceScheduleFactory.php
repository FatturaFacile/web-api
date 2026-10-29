<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\Subscription;

class InvoiceScheduleFactory extends Factory
{
    protected $model = InvoiceSchedule::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'subscription_id' => Subscription::factory(),
            'scheduled_billing_date' => now()->toDateString(),
            'service_period_start' => now()->startOfMonth()->toDateString(),
            'service_period_end' => now()->endOfMonth()->toDateString(),
            'estimated_amount' => 120.00,
            'status' => 'scheduled',
        ];
    }
}
