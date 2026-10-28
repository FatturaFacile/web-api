<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\PurchaseReceiptLine;

/**
 * @extends Factory<PurchaseReceiptLine>
 */
class PurchaseReceiptLineFactory extends Factory
{
    protected $model = PurchaseReceiptLine::class;

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

        $receipt = PurchaseReceipt::first() ?? PurchaseReceipt::factory()->create(['tenant_id' => $tenantId]);
        $orderLine = PurchaseOrderLine::first() ?? PurchaseOrderLine::factory()->create(['tenant_id' => $tenantId]);

        $quantity = fake()->numberBetween(10, 50);

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'purchase_receipt_id' => $receipt->id,
            'purchase_order_line_id' => $orderLine->id,
            'quantity_received' => $quantity,
            'quantity_accepted' => $quantity,
            'quantity_rejected' => 0.0,
            'lot_number' => 'LOT-'.fake()->numerify('#####'),
        ];
    }
}
