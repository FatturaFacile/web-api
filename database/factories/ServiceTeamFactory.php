<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\ServiceTeam;

class ServiceTeamFactory extends Factory
{
    protected $model = ServiceTeam::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'TEAM-'.strtoupper(Str::random(6)),
            'name' => fake()->jobTitle().' Team',
            'is_active' => true,
            'metadata' => [
                'team_type' => fake()->randomElement(['helpdesk', 'field_service', 'network_ops', 'tier2_support']),
            ],
        ];
    }
}
