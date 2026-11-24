<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Planning\Models\PlanningPublication;
use Modules\Planning\Models\PlanningScenario;

/**
 * @extends Factory<PlanningPublication>
 */
final class PlanningPublicationFactory extends Factory
{
    protected $model = PlanningPublication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'scenario_id' => PlanningScenario::factory(),
            'version' => 1,
            'is_current' => true,
            'snapshot' => [],
            'published_at' => now(),
        ];
    }
}
