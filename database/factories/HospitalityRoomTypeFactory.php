<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Hospitality\Models\RoomType;

/**
 * @extends Factory<RoomType>
 */
final class HospitalityRoomTypeFactory extends Factory
{
    protected $model = RoomType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->words(2, true),
            'max_occupancy' => 2,
            'base_rate' => '100.00',
            'is_active' => true,
        ];
    }
}
