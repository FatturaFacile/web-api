<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\ServiceTechnician;

class ServiceTechnicianFactory extends Factory
{
    protected $model = ServiceTechnician::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'technician_number' => 'TECH-'.strtoupper(Str::random(6)),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'skill_level' => 'intermediate',
            'current_status' => 'available',
            'hourly_cost_rate' => 35.00,
            'hourly_billable_rate' => 85.00,
            'is_available' => true,
        ];
    }

    public function available(): static
    {
        return $this->state(fn () => [
            'current_status' => 'available',
            'is_available' => true,
        ]);
    }

    public function busy(): static
    {
        return $this->state(fn () => [
            'current_status' => 'on_assignment',
            'is_available' => false,
        ]);
    }

    public function onLeave(): static
    {
        return $this->state(fn () => [
            'current_status' => 'on_leave',
            'is_available' => false,
        ]);
    }

    public function offDuty(): static
    {
        return $this->state(fn () => [
            'current_status' => 'offline',
            'is_available' => false,
        ]);
    }
}
