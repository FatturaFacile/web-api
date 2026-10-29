<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Invoicing\Models\SalesOrder;

/**
 * @extends Factory<SalesOrder>
 */
final class SalesOrderFactory extends Factory
{
    protected $model = SalesOrder::class;

    public function definition(): array
    {
        $tenantId = (string) Str::uuid7();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'contact_id' => Contact::factory(),
            'order_number' => 'SO-2027-'.$this->faker->numerify('#####'),
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'EUR',
            'subtotal' => 2000.00,
            'discount_amount' => 100.00,
            'total_net' => 1900.00,
            'total_tax' => 418.00,
            'total_gross' => 2318.00,
            'status' => 'confirmed',
            'credit_status' => 'approved',
            'inventory_status' => 'pending',
            'fulfillment_status' => 'unfulfilled',
            'billing_status' => 'unbilled',
            'payment_terms' => 'TP02',
            'shipping_method' => 'Express Courier',
            'notes' => 'Factory sales order notes',
        ];
    }

    public function onHold(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'on_hold',
            'credit_status' => 'on_hold',
            'credit_hold_reason' => 'Credit limit exceeded in factory',
        ]);
    }

    public function fulfilled(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'shipped',
            'fulfillment_status' => 'fulfilled',
            'inventory_status' => 'dispatched',
        ]);
    }
}
