<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Associations\Models\AccessArea;
use Modules\Associations\Models\AccessDevice;

/**
 * @extends Factory<AccessDevice>
 */
final class AccessDeviceFactory extends Factory
{
    protected $model = AccessDevice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'area_id' => static fn (array $attributes): string => AccessArea::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'name' => 'Tornello '.fake()->numberBetween(1, 20),
            'device_key' => Str::lower(Str::random(32)),
            'secret_hash' => hash('sha256', Str::random(48)),
            'direction' => 'in',
            'is_active' => true,
            'last_seen_at' => now()->subMinutes(fake()->numberBetween(1, 120)),
        ];
    }
}
