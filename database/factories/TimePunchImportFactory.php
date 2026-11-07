<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\TimePunchImport;

/**
 * @extends Factory<TimePunchImport>
 */
final class TimePunchImportFactory extends Factory
{
    protected $model = TimePunchImport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'reader_id' => 'TERM-INGRESSO-'.fake()->numerify('##'),
            'external_id' => fake()->unique()->uuid(),
            'user_id' => User::factory(),
            'time_clock_event_id' => null,
            'time_meal_booking_id' => null,
            'imported_at' => '2026-11-02 08:01:00',
        ];
    }
}
