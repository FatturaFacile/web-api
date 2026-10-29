<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\LeaveType;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'employee_id' => function (array $attrs) {
                return Employee::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'leave_type_id' => function (array $attrs) {
                return LeaveType::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'request_number' => 'LR-'.strtoupper(Str::random(8)),
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(14)->toDateString(),
            'total_hours' => 40.00,
            'total_days' => 5.00,
            'status' => 'pending_approval',
            'reason' => 'Annual family holiday',
            'is_locked' => false,
            'metadata' => ['source' => 'factory'],
        ];
    }
}
