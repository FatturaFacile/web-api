<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\OnboardingChecklist;
use Modules\HumanResources\Models\OnboardingTemplate;

/**
 * @extends Factory<OnboardingChecklist>
 */
final class OnboardingChecklistFactory extends Factory
{
    protected $model = OnboardingChecklist::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'employee_id' => static fn (array $attributes): string => (string) Employee::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'onboarding_template_id' => static fn (array $attributes): string => (string) OnboardingTemplate::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'completed_at' => null,
        ];
    }
}
