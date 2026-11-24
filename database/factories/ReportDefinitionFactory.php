<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Analytics\Models\ReportDefinition;

/**
 * @extends Factory<ReportDefinition>
 */
final class ReportDefinitionFactory extends Factory
{
    protected $model = ReportDefinition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => null,
            'kpis' => ['sales', 'purchases'],
            'period_preset' => 'month_to_date',
            'from_date' => null,
            'to_date' => null,
            'compare' => 'previous_period',
            'is_shared' => false,
        ];
    }

    /**
     * Visible to every analytics reader of the company.
     */
    public function shared(): static
    {
        return $this->state(['is_shared' => true]);
    }
}
