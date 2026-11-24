<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\PortalMovementRequest;

/**
 * @extends Factory<PortalMovementRequest>
 */
final class PortalMovementRequestFactory extends Factory
{
    protected $model = PortalMovementRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'contact_id' => static fn (array $attributes): string => (string) Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'warehouse_id' => static fn (array $attributes): string => (string) WarehouseFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'stock_item_id' => static fn (array $attributes): string => (string) StockItemFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'lot_id' => null,
            'request_type' => 'withdrawal',
            'quantity' => '12.0000',
            'requested_date' => '2026-11-20',
            'notes' => 'Ritiro merce in conto deposito',
            'status' => 'pending',
        ];
    }
}
