<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;

/**
 * @extends Factory<DepotRepairRmaOrder>
 */
class DepotRepairRmaOrderFactory extends Factory
{
    protected $model = DepotRepairRmaOrder::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'equipment_passport_id' => SerializedEquipmentPassport::factory(),
            'tenant_id' => fn (array $attributes) => $this->passport($attributes)?->tenant_id,
            'customer_id' => fn (array $attributes) => $this->passport($attributes)?->current_owner_customer_id,
            'rma_number' => 'RMA-'.now()->format('Y').'-'.str_pad((string) fake()->unique()->numberBetween(900000, 999999), 6, '0', STR_PAD_LEFT),
            'status' => 'awaiting_arrival',
            'warranty_status' => 'covered_in_warranty',
            'warranty_evaluated_at' => now(),
            'reported_symptom' => fake()->sentence(),
            'currency' => 'EUR',
            'preauthorized_threshold' => 0.00,
            'estimate_approval_status' => 'not_required',
            'ddt_causale_outbound' => 'conto_riparazione',
            'is_non_ownership_transfer' => true,
            'vat_exempt_art15_dpr633' => false,
        ];
    }

    public function returned(): static
    {
        return $this->state(fn () => [
            'status' => 'returned',
            'received_at' => now()->subDays(20),
            'returned_at' => now()->subDays(10),
            'repair_outcome' => 'repaired',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function passport(array $attributes): ?SerializedEquipmentPassport
    {
        return SerializedEquipmentPassport::withoutGlobalScopes()->find($attributes['equipment_passport_id']);
    }
}
