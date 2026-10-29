<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;

/**
 * @extends Factory<PurchaseOrderLine>
 */
class PurchaseOrderLineFactory extends Factory
{
    protected $model = PurchaseOrderLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? Tenant::create([
            'id' => (string) Str::uuid(),
            'name' => fake()->company(),
            'legal_name' => fake()->company(),
            'country' => 'IT',
            'default_currency' => 'EUR',
        ])->id;

        $order = PurchaseOrder::first() ?? PurchaseOrder::factory()->create(['tenant_id' => $tenantId]);

        $quantity = fake()->numberBetween(5, 100);
        $unitPrice = fake()->randomFloat(2, 10, 200);
        $net = round($quantity * $unitPrice, 2);
        $tax = round($net * 0.22, 2);
        $total = $net + $tax;

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'purchase_order_id' => $order->id,
            'line_number' => fake()->numberBetween(1, 10),
            'description' => fake()->words(3, true),
            'quantity_ordered' => $quantity,
            'quantity_received' => 0.0,
            'quantity_invoiced' => 0.0,
            'quantity_cancelled' => 0.0,
            'uom' => 'PCS',
            'unit_price' => $unitPrice,
            'tax_rate' => 22.0,
            'line_net_amount' => $net,
            'line_tax_amount' => $tax,
            'line_total_amount' => $total,
            'is_closed' => false,
        ];
    }

    public function received(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity_received' => $attributes['quantity_ordered'],
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_closed' => true,
            'quantity_received' => $attributes['quantity_ordered'],
            'quantity_invoiced' => $attributes['quantity_ordered'],
        ]);
    }
}
