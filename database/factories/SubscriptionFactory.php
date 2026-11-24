<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;

class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        $start = now();
        $end = $start->copy()->addMonths(12)->subDay();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'subscription_number' => 'SUB-'.date('Ymd').'-'.strtoupper(Str::random(6)),
            'customer_id' => (string) Str::uuid7(),
            'plan_id' => SubscriptionPlan::factory(),
            'status' => 'active',
            'start_date' => $start->toDateString(),
            'current_period_start' => $start->toDateString(),
            'current_period_end' => $end->toDateString(),
            'renewal_type' => 'evergreen',
            'contract_term_months' => 12,
            'auto_renew' => true,
            'cancellation_notice_days' => 30,
            'currency' => 'EUR',
            'billing_day_of_month' => 1,
            'payment_terms_days' => 30,
            'payment_method' => 'sepa_direct_debit',
            'mrr' => 120.00,
            'arr' => 1440.00,
            'is_locked' => false,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft']);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => 'paused',
            'cancellation_reason' => 'Administrative payment hold',
        ]);
    }

    public function renewing(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'current_period_end' => now()->addDays(5)->toDateString(),
        ]);
    }

    public function renewed(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'current_period_start' => now()->toDateString(),
            'current_period_end' => now()->addYear()->subDay()->toDateString(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => 'Customer voluntary churn',
            'mrr' => 0.00,
            'arr' => 0.00,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'expired',
            'current_period_end' => now()->subDay()->toDateString(),
            'auto_renew' => false,
        ]);
    }

    public function upgraded(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'mrr' => 250.00,
            'arr' => 3000.00,
        ]);
    }

    public function downgraded(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'mrr' => 60.00,
            'arr' => 720.00,
        ]);
    }
}
