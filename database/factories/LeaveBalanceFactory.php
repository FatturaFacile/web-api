<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\LeaveBalance;
use Modules\HumanResources\Models\LeaveType;

/**
 * @extends Factory<LeaveBalance>
 */
class LeaveBalanceFactory extends Factory
{
    protected $model = LeaveBalance::class;

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
            'fiscal_year' => (int) date('Y'),
            'entitlement_hours' => 160.00,
            'carryover_hours' => 16.00,
            'accrued_hours' => 160.00,
            'used_hours' => 40.00,
            'pending_hours' => 0.00,
            'available_hours' => 136.00,
            'last_accrued_at' => now(),
        ];
    }
}
