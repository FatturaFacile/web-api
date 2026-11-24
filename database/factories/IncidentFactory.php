<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\Incident;

class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'incident_number' => 'INC-'.date('Ymd').'-'.strtoupper(Str::random(6)),
            'title' => fake()->sentence(3),
            'summary' => fake()->paragraph(),
            'severity' => 'P3',
            'impact' => 'moderate',
            'urgency' => 'medium',
            'priority' => 'P3',
            'status' => 'identified',
            'reported_at' => now(),
            'is_locked' => false,
        ];
    }

    public function identified(): static
    {
        return $this->state(fn () => ['status' => 'identified']);
    }

    public function investigating(): static
    {
        return $this->state(fn () => ['status' => 'investigating']);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => 'resolved',
            'resolution_summary' => 'Service restored successfully',
            'resolved_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => 'closed',
            'resolved_at' => now()->subDay(),
            'closed_at' => now(),
        ]);
    }

    public function major(): static
    {
        return $this->state(fn () => [
            'severity' => 'critical',
            'impact' => 'extensive',
            'urgency' => 'immediate',
            'is_major_incident' => true,
        ]);
    }
}
