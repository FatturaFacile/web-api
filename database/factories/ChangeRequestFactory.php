<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\ChangeRequest;

class ChangeRequestFactory extends Factory
{
    protected $model = ChangeRequest::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'change_number' => 'CR-'.date('Ymd').'-'.strtoupper(Str::random(6)),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'change_type' => fake()->randomElement(['standard', 'normal', 'emergency']),
            'risk_level' => fake()->randomElement(['low', 'medium', 'high']),
            'status' => 'submitted',
            'impact_assessment' => 'Routine software service pack application',
            'implementation_plan' => 'Step-by-step rolling deployment',
            'rollback_plan' => 'Immediate DNS redirect to standby cluster',
            'is_locked' => false,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => ['status' => 'submitted']);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => 'approved',
            'cab_decision' => 'approved',
        ]);
    }

    public function implemented(): static
    {
        return $this->state(fn () => [
            'status' => 'implemented',
            'cab_decision' => 'approved',
            'implemented_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => 'rejected',
            'cab_decision' => 'rejected',
        ]);
    }
}
