<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\OnboardingChecklist;
use Modules\HumanResources\Models\OnboardingTask;

/**
 * @extends Factory<OnboardingTask>
 */
final class OnboardingTaskFactory extends Factory
{
    protected $model = OnboardingTask::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'onboarding_checklist_id' => static fn (array $attributes): string => (string) OnboardingChecklist::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'title' => fake()->randomElement(['Consegna badge e postazione di lavoro', 'Formazione sicurezza sul lavoro', 'Attivazione account e posta elettronica']),
            'due_on' => '2027-01-11',
            'sort_order' => fake()->numberBetween(0, 5),
            'completed_at' => null,
            'completed_by' => null,
        ];
    }
}
