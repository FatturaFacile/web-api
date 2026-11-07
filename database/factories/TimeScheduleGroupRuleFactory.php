<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\TimeScheduleGroup;
use Modules\HumanResources\Models\TimeScheduleGroupRule;
use Modules\HumanResources\Services\TimeTracking\PunchRoundingMode;

/**
 * @extends Factory<TimeScheduleGroupRule>
 */
final class TimeScheduleGroupRuleFactory extends Factory
{
    protected $model = TimeScheduleGroupRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'schedule_group_id' => static fn (array $attributes): string => (string) TimeScheduleGroup::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'valid_from' => '2027-01-01',
            'tolerance_before_minutes' => 5,
            'tolerance_after_minutes' => 5,
            'rounding_minutes' => 15,
            'rounding_mode' => PunchRoundingMode::Nearest->value,
            'night_allowance_percent' => '25.0000',
            'sunday_allowance_percent' => '50.0000',
            'holiday_allowance_percent' => '50.0000',
            'daily_allowance_amount' => '15.00',
            'daily_allowance_minimum_minutes' => 360,
            'created_by' => null,
        ];
    }
}
