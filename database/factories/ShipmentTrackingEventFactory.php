<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\ShipmentTrackingEvent;

/**
 * @extends Factory<ShipmentTrackingEvent>
 */
final class ShipmentTrackingEventFactory extends Factory
{
    protected $model = ShipmentTrackingEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'shipment_id' => static fn (array $attributes): string => (string) ShipmentFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'status' => 'in_transit',
            'description' => 'Spedizione in transito presso hub di smistamento',
            'location' => 'Hub di Bologna',
            'occurred_at' => '2026-11-03 06:42:00',
            'source' => 'manual',
        ];
    }
}
