<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\ApplicationStageEvent;
use Modules\HumanResources\Models\JobApplication;
use Modules\HumanResources\Models\PipelineStage;

/**
 * @extends Factory<ApplicationStageEvent>
 */
final class ApplicationStageEventFactory extends Factory
{
    protected $model = ApplicationStageEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'job_application_id' => static fn (array $attributes): string => (string) JobApplication::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'pipeline_stage_id' => static fn (array $attributes): string => (string) (JobApplication::query()->withoutGlobalScopes()->whereKey($attributes['job_application_id'])->value('pipeline_stage_id')
                ?? PipelineStage::factory()->create(['tenant_id' => $attributes['tenant_id']])->id),
            'actor_id' => null,
            'occurred_at' => fake()->dateTimeBetween('2026-11-01', '2026-11-30'),
        ];
    }
}
