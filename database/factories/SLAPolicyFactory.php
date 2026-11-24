<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\SLAPolicy;

class SLAPolicyFactory extends Factory
{
    protected $model = SLAPolicy::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'SLA-'.strtoupper(Str::random(6)),
            'name' => fake()->word().' SLA Policy',
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'response_time_minutes' => 240,
            'resolution_time_minutes' => 1440,
            'escalation_time_minutes' => 120,
            'business_hours_only' => true,
            'is_active' => true,
        ];
    }
}
