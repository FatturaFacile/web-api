<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Hospitality\Models\Stay;

/**
 * @extends Factory<Stay>
 */
final class HospitalityStayFactory extends Factory
{
    protected $model = Stay::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'number' => 'STY-'.fake()->year().'-'.fake()->unique()->numerify('####'),
            'guest_name' => fake()->name(),
            'room_type_id' => HospitalityRoomTypeFactory::new(),
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDay()->toDateString(),
            'adults' => 1,
            'status' => 'booked',
            'room_total' => '100.00',
            'nightly_rates' => ['100.00'],
        ];
    }
}
