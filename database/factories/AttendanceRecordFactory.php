<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\AttendanceRecord;
use Modules\HumanResources\Models\Employee;

/**
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    protected $model = AttendanceRecord::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'employee_id' => function (array $attrs) {
                return Employee::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'work_date' => now()->toDateString(),
            'clock_in_at' => now()->setTime(8, 30),
            'clock_out_at' => now()->setTime(17, 30),
            'break_minutes' => 60,
            'regular_hours' => 8.00,
            'overtime_hours' => 0.00,
            'night_hours' => 0.00,
            'holiday_hours' => 0.00,
            'status' => 'present',
            'source' => 'web_portal',
            'notes' => 'Standard shift',
        ];
    }
}
