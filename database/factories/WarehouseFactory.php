<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Warehouse;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? Tenant::create([
            'id' => (string) Str::uuid7(),
            'name' => fake()->company(),
            'legal_name' => fake()->company(),
            'country' => 'IT',
            'default_currency' => 'EUR',
        ])->id;

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'code' => 'WH-'.strtoupper(fake()->unique()->bothify('??-##')),
            'name' => fake()->city().' Logistics Center',
            'warehouse_type' => 'standard',
            'address' => ['street' => fake()->streetAddress(), 'city' => fake()->city(), 'country' => 'IT'],
            'is_active' => true,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function van(): static
    {
        return $this->state(fn () => [
            'warehouse_type' => 'vehicle_van',
            'name' => 'Service Van #'.fake()->randomNumber(2),
        ]);
    }

    public function transit(): static
    {
        return $this->state(fn () => [
            'warehouse_type' => 'transit',
            'name' => 'In-Transit Virtual Depot',
        ]);
    }
}
