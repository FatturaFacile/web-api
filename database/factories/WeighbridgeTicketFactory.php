<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\WeighbridgeTicket;
use Modules\Inventory\Models\WeighbridgeVehicle;

/**
 * @extends Factory<WeighbridgeTicket>
 */
final class WeighbridgeTicketFactory extends Factory
{
    protected $model = WeighbridgeTicket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'ticket_number' => strtoupper(fake()->unique()->bothify('PES-2026-#####')),
            'weighbridge_vehicle_id' => static fn (array $attributes): string => (string) WeighbridgeVehicleFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'contact_id' => static fn (array $attributes): ?string => WeighbridgeVehicle::query()->withoutGlobalScopes()->whereKey($attributes['weighbridge_vehicle_id'])->value('contact_id'),
            'stock_item_id' => static fn (array $attributes): string => (string) StockItemFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'name' => 'Ghiaia lavata 8/16',
                'uom' => 'KG',
            ])->id,
            'direction' => 'outbound',
            'status' => 'open',
            'first_weight_kg' => '14250.000',
            'first_weighed_at' => '2026-11-04 07:55:00',
            'used_stored_tare' => false,
        ];
    }
}
