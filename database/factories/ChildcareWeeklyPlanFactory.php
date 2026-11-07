<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Childcare\Models\WeeklyPlan;

/**
 * @extends Factory<WeeklyPlan>
 */
final class ChildcareWeeklyPlanFactory extends Factory
{
    protected $model = WeeklyPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fullDay = ['from' => '08:00', 'to' => '16:30', 'meals' => ['lunch', 'snack']];

        return [
            'tenant_id' => Tenant::factory(),
            'child_id' => static fn (array $attributes): string => (string) ChildcareChildFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'valid_from' => '2026-09-01',
            'valid_to' => '2027-06-30',
            'days' => [
                'mon' => $fullDay,
                'tue' => $fullDay,
                'wed' => ['from' => '08:00', 'to' => '12:00', 'meals' => []],
                'thu' => $fullDay,
                'fri' => $fullDay,
            ],
        ];
    }
}
