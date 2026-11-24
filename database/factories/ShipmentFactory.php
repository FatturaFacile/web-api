<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\Shipment;

/**
 * @extends Factory<Shipment>
 */
final class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'shipping_carrier_id' => static fn (array $attributes): string => (string) ShippingCarrierFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'stock_packing_list_id' => null,
            'delivery_note_id' => null,
            'shipment_number' => strtoupper(fake()->unique()->bothify('SHP-2026-######')),
            'status' => 'booked',
            'tracking_number' => fake()->numerify('0######################'),
            'carrier_reference' => null,
            'recipient' => [
                'name' => fake()->company(),
                'street' => 'Via Giuseppe Garibaldi 21',
                'postal_code' => '20121',
                'city' => 'Milano',
                'country' => 'IT',
                'phone' => '+39 02 1234567',
            ],
            'packages_count' => 2,
            'gross_weight_kg' => '18.400',
            'label_format' => null,
            'label_content' => null,
            'delivered_at' => null,
            'created_by' => null,
        ];
    }
}
