<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\PurchaseRequisitionLine;
use Modules\Procurement\Models\Supplier;

/**
 * @extends Factory<PurchaseRequisitionLine>
 */
class PurchaseRequisitionLineFactory extends Factory
{
    protected $model = PurchaseRequisitionLine::class;

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

        $requisition = PurchaseRequisition::first() ?? PurchaseRequisition::factory()->create(['tenant_id' => $tenantId]);
        $supplier = Supplier::first();

        $quantity = fake()->numberBetween(1, 50);
        $unitPrice = fake()->randomFloat(2, 10, 500);

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'purchase_requisition_id' => $requisition->id,
            'line_number' => fake()->numberBetween(1, 10),
            'description' => fake()->words(3, true),
            'quantity_requested' => $quantity,
            'uom' => 'PCS',
            'estimated_unit_price' => $unitPrice,
            'estimated_total_price' => $quantity * $unitPrice,
            'suggested_supplier_id' => $supplier?->id,
        ];
    }
}
