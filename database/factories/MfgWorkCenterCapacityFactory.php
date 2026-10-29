<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Models\MfgWorkCenterCapacity;

/**
 * @extends Factory<MfgWorkCenterCapacity>
 */
final class MfgWorkCenterCapacityFactory extends Factory
{
    protected $model = MfgWorkCenterCapacity::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'work_center_id' => MfgWorkCenter::factory(),
            'calendar_date' => now()->toDateString(),
            'available_hours' => 8.00,
            'booked_hours' => 4.00,
            'utilization_rate' => 50.00,
        ];
    }
}
