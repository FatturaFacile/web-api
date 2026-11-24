<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Facility\Models\FacilityBookingRule;

/**
 * @extends Factory<FacilityBookingRule>
 */
final class FacilityBookingRuleFactory extends Factory
{
    protected $model = FacilityBookingRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'space_kind' => 'desk',
            'max_bookings_per_user_per_day' => 1,
            'max_minutes_per_booking' => 600,
            'max_advance_days' => 30,
            'checkin_grace_minutes' => 15,
            'requires_checkin' => true,
        ];
    }
}
