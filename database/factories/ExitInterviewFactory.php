<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Enums\ExitReasonCode;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\ExitInterview;

/**
 * @extends Factory<ExitInterview>
 */
final class ExitInterviewFactory extends Factory
{
    protected $model = ExitInterview::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'employee_id' => static fn (array $attributes): string => (string) Employee::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'conducted_on' => '2026-11-10',
            'reason_code' => fake()->randomElement(ExitReasonCode::values()),
            'would_recommend' => true,
            'eligible_for_rehire' => true,
            'satisfaction_rating' => fake()->numberBetween(1, 5),
            'feedback' => 'Good team, limited career growth.',
        ];
    }
}
