<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\PurchaseReturn;
use Modules\Procurement\Models\PurchaseReturnLine;

/**
 * @extends Factory<PurchaseReturnLine>
 */
class PurchaseReturnLineFactory extends Factory
{
    protected $model = PurchaseReturnLine::class;

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

        $return = PurchaseReturn::first() ?? PurchaseReturn::factory()->create(['tenant_id' => $tenantId]);
        $orderLine = PurchaseOrderLine::first() ?? PurchaseOrderLine::factory()->create(['tenant_id' => $tenantId]);

        $unitPrice = (float) ($orderLine->unit_price ?? 25.00);
        $quantity = 5.0;
        $total = round($quantity * $unitPrice, 2);

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'purchase_return_id' => $return->id,
            'purchase_order_line_id' => $orderLine->id,
            'quantity_returned' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $total,
            'reason' => 'Defect found during incoming inspection',
        ];
    }
}
