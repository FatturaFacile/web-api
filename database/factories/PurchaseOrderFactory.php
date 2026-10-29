<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Supplier;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

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

        $user = User::first();
        $supplier = Supplier::first() ?? Supplier::factory()->create(['tenant_id' => $tenantId]);

        $net = fake()->randomFloat(2, 500, 10000);
        $tax = round($net * 0.22, 2);
        $total = $net + $tax;
        $poNumber = 'PO-'.fake()->unique()->numerify('####');

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'supplier_id' => $supplier->id,
            'po_number' => $poNumber,
            'order_number' => $poNumber,
            'order_type' => 'standard',
            'date' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(14)->toDateString(),
            'delivery_due_date' => now()->addDays(14)->toDateString(),
            'status' => 'draft',
            'currency' => 'EUR',
            'exchange_rate' => 1.0,
            'net_amount' => $net,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'total_net' => $net,
            'total_tax' => $tax,
            'total_gross' => $total,
            'budget_committed_amount' => $total,
            'payment_terms_code' => 'NET30',
            'incoterms' => 'DAP',
            'is_budget_encumbered' => false,
            'is_locked' => false,
            'created_by' => $user?->id,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
            'is_budget_encumbered' => false,
            'is_locked' => false,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'is_budget_encumbered' => true,
            'is_locked' => false,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'is_budget_encumbered' => false,
            'is_locked' => false,
        ]);
    }

    public function partiallyReceived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'partially_received',
            'is_budget_encumbered' => true,
        ]);
    }

    public function received(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'received',
            'is_budget_encumbered' => true,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
            'is_locked' => false,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
            'is_budget_encumbered' => false,
            'is_locked' => false,
        ]);
    }
}
