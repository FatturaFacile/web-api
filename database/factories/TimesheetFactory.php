<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\Timesheet;

/**
 * @extends Factory<Timesheet>
 */
class TimesheetFactory extends Factory
{
    protected $model = Timesheet::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'employee_id' => function (array $attrs) {
                return Employee::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'timesheet_number' => 'TS-'.strtoupper(Str::random(8)),
            'work_date' => now()->toDateString(),
            'regular_hours' => 8.00,
            'overtime_hours' => 2.00,
            'total_hours' => 10.00,
            'billable_hours' => 8.00,
            'hourly_cost_rate' => 35.00,
            'total_labor_cost' => 350.00,
            'status' => 'submitted',
            'is_locked' => false,
            'description' => 'Engineering Design & Verification',
            'metadata' => ['source' => 'factory'],
        ];
    }
}
