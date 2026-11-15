<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Hospitality\Models\Accommodation;

/**
 * @extends Factory<Accommodation>
 */
final class HospitalityAccommodationFactory extends Factory
{
    protected $model = Accommodation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'room_type_id' => HospitalityRoomTypeFactory::new(),
            'number' => (string) fake()->unique()->numberBetween(100, 9999),
            'status' => 'available',
            'housekeeping_status' => 'clean',
        ];
    }
}
