<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\TimeScheduleGroup;
use Modules\HumanResources\Models\TimeScheduleGroupMember;

/**
 * @extends Factory<TimeScheduleGroupMember>
 */
final class TimeScheduleGroupMemberFactory extends Factory
{
    protected $model = TimeScheduleGroupMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'schedule_group_id' => static fn (array $attributes): string => (string) TimeScheduleGroup::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'user_id' => User::factory(),
            'valid_from' => '2027-01-01',
            'valid_to' => null,
        ];
    }
}
