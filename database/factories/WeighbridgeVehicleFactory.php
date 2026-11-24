<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\WeighbridgeVehicle;

/**
 * @extends Factory<WeighbridgeVehicle>
 */
final class WeighbridgeVehicleFactory extends Factory
{
    protected $model = WeighbridgeVehicle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'license_plate' => strtoupper(fake()->unique()->bothify('??###??')),
            'card_number' => fake()->numerify('CARD-######'),
            'contact_id' => static fn (array $attributes): string => (string) Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'stored_tare_kg' => '14250.000',
            'tare_recorded_on' => '2026-09-01',
            'notes' => null,
            'is_active' => true,
        ];
    }
}
