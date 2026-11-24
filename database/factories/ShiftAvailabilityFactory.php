<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Shift;
use Modules\Associations\Models\ShiftAvailability;
use Modules\Associations\Models\Volunteer;

/**
 * @extends Factory<ShiftAvailability>
 */
final class ShiftAvailabilityFactory extends Factory
{
    protected $model = ShiftAvailability::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'shift_id' => static fn (array $attributes): string => Shift::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'volunteer_id' => static fn (array $attributes): string => Volunteer::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'member_id' => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            ])->id,
            'is_available' => true,
        ];
    }
}
