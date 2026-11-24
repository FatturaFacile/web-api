<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\AssetLocation;

/**
 * @extends Factory<AssetLocation>
 */
final class AssetLocationFactory extends Factory
{
    protected $model = AssetLocation::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'LOC-'.strtoupper(Str::random(6)),
            'name' => fake()->city().' Plant',
            'location_type' => 'facility',
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'country' => 'IT',
            'is_active' => true,
        ];
    }
}
