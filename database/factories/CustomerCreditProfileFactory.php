<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Invoicing\Models\CustomerCreditProfile;

/**
 * @extends Factory<CustomerCreditProfile>
 */
final class CustomerCreditProfileFactory extends Factory
{
    protected $model = CustomerCreditProfile::class;

    public function definition(): array
    {
        $tenantId = (string) Str::uuid7();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'customer_id' => Contact::factory(),
            'credit_limit' => 50000.00,
            'used_credit' => 10000.00,
            'pending_orders_amount' => 5000.00,
            'total_exposure' => 15000.00,
            'overdue_amount' => 0.00,
            'overdue_invoices_count' => 0,
            'max_overdue_days' => 0,
            'credit_status' => 'good',
            'risk_rating' => 'low',
            'is_credit_hold' => false,
            'last_evaluated_at' => now(),
        ];
    }

    public function onHold(): self
    {
        return $this->state(fn (array $attributes) => [
            'credit_status' => 'hold',
            'risk_rating' => 'high',
            'is_credit_hold' => true,
            'hold_reason' => 'Credit limit exceeded factory state',
        ]);
    }
}
