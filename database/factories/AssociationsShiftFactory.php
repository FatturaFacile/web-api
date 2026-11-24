<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Shift;

/**
 * @extends Factory<Shift>
 */
final class AssociationsShiftFactory extends Factory
{
    protected $model = Shift::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'title' => fake()->randomElement(['Servizio ambulanza', 'Presidio sagra', 'Turno banco alimentare']),
            'service' => 'Protezione civile',
            'location' => fake()->city(),
            'starts_at' => '2026-11-21 08:00:00',
            'ends_at' => '2026-11-21 14:00:00',
            'required_volunteers' => 2,
            'status' => Shift::Planned,
            'notes' => null,
        ];
    }
}
