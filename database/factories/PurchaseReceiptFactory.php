<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseReceipt;

/**
 * @extends Factory<PurchaseReceipt>
 */
class PurchaseReceiptFactory extends Factory
{
    protected $model = PurchaseReceipt::class;

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
        $order = PurchaseOrder::first() ?? PurchaseOrder::factory()->create(['tenant_id' => $tenantId]);

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'purchase_order_id' => $order->id,
            'receipt_number' => 'GRN-'.fake()->unique()->numerify('####'),
            'supplier_delivery_note_ref' => 'DDT-'.fake()->numerify('#####'),
            'received_date' => now()->toDateString(),
            'received_by_user_id' => $user?->id,
            'inspection_status' => 'passed',
            'notes' => fake()->sentence(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'inspection_status' => 'failed',
            'notes' => 'Damaged outer packaging during transit',
        ]);
    }

    public function conditional(): static
    {
        return $this->state(fn (array $attributes) => [
            'inspection_status' => 'conditional',
            'notes' => 'Minor visual flaw, accepted conditionally',
        ]);
    }

    public function quarantined(): static
    {
        return $this->state(fn (array $attributes) => [
            'inspection_status' => 'quarantined',
            'notes' => 'Awaiting laboratory batch test release',
        ]);
    }
}
