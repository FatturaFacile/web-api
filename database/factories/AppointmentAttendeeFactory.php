<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\AppointmentAttendee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentAttendee>
 */
class AppointmentAttendeeFactory extends Factory
{
    protected $model = AppointmentAttendee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? Tenant::create([
            'name' => fake()->company(),
            'legal_name' => fake()->company(),
            'country' => 'IT',
            'default_currency' => 'EUR',
        ])->id;

        return [
            'tenant_id' => $tenantId,
            'appointment_id' => Appointment::factory(),
            'user_id' => User::first()?->id ?? User::factory(),
            'role' => 'lead',
            'is_organizer' => true,
            'status' => 'accepted',
        ];
    }
}
