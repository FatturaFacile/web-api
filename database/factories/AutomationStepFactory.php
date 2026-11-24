<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\AutomationSequence;
use Modules\CRM\Models\AutomationStep;

/**
 * @extends Factory<AutomationStep>
 */
final class AutomationStepFactory extends Factory
{
    protected $model = AutomationStep::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'sequence_id' => AutomationSequence::factory(),
            'position' => 1,
            'step_type' => 'email',
            'delay_days' => 0,
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
        ];
    }
}
