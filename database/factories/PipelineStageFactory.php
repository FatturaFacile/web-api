<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\PipelineStage;

/**
 * @extends Factory<PipelineStage>
 */
final class PipelineStageFactory extends Factory
{
    protected $model = PipelineStage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->randomElement(['Candidatura ricevuta', 'Screening', 'Colloquio tecnico', 'Colloquio HR', 'Offerta']).' '.fake()->unique()->numerify('###'),
            'sort_order' => fake()->numberBetween(0, 10),
            'outcome' => null,
        ];
    }

    public function hired(): static
    {
        return $this->state(['name' => 'Assunto '.fake()->unique()->numerify('###'), 'outcome' => PipelineStage::OutcomeHired]);
    }

    public function rejected(): static
    {
        return $this->state(['name' => 'Scartato '.fake()->unique()->numerify('###'), 'outcome' => PipelineStage::OutcomeRejected]);
    }
}
