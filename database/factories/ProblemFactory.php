<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\Problem;

class ProblemFactory extends Factory
{
    protected $model = Problem::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'problem_number' => 'PRB-'.date('Ymd').'-'.strtoupper(Str::random(6)),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'status' => 'under_investigation',
            'is_known_error' => false,
        ];
    }

    public function investigating(): static
    {
        return $this->state(fn () => ['status' => 'investigating']);
    }

    public function knownError(): static
    {
        return $this->state(fn () => [
            'status' => 'known_error',
            'is_known_error' => true,
            'workaround' => 'Restart microservice pod every 24 hours',
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => 'resolved',
            'permanent_solution' => 'Memory leak patched in release v3.4.1',
        ]);
    }
}
