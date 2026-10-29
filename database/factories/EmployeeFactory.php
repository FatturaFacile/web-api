<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\JobGrade;
use Modules\HumanResources\Models\Position;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        $salary = fake()->randomFloat(2, 36000, 95000);
        $hourlyRate = round(($salary / 2080) * 1.35, 2);
        $first = fake()->firstName();
        $last = fake()->lastName();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'department_id' => function (array $attrs) {
                return Department::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'position_id' => function (array $attrs) {
                return Position::factory()->create([
                    'tenant_id' => $attrs['tenant_id'],
                    'department_id' => $attrs['department_id'],
                ])->id;
            },
            'job_grade_id' => function (array $attrs) {
                return JobGrade::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'employee_number' => 'EMP-'.strtoupper(Str::random(8)),
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => $first.' '.$last,
            'work_email' => strtolower(Str::random(6)).'.'.fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'tax_code' => 'TAX'.strtoupper(Str::random(13)),
            'birth_date' => '1988-05-15',
            'nationality' => 'IT',
            'employment_status' => 'active',
            'employment_type' => 'full_time',
            'hire_date' => now()->subYears(2)->toDateString(),
            'probation_end_date' => now()->subMonths(21)->toDateString(),
            'confirmation_date' => now()->subMonths(21)->toDateString(),
            'base_annual_salary' => $salary,
            'hourly_cost_rate' => $hourlyRate,
            'fte_ratio' => 1.00,
            'currency' => 'EUR',
            'work_schedule_code' => 'STD-40H',
            'approval_status' => 'approved',
            'is_locked' => false,
            'emergency_contact' => [
                'name' => 'Elena Rossi',
                'relationship' => 'Spouse',
                'phone' => '+39 02 5550199',
            ],
            'equipment_assigned' => [],
            'metadata' => ['source' => 'factory'],
        ];
    }

    public function candidate(): static
    {
        return $this->state(fn (array $attributes) => [
            'employment_status' => 'candidate',
            'hire_date' => now()->addDays(15)->toDateString(),
            'probation_end_date' => null,
            'confirmation_date' => null,
            'termination_date' => null,
        ]);
    }

    public function onboarding(): static
    {
        return $this->state(fn (array $attributes) => [
            'employment_status' => 'onboarding',
            'hire_date' => now()->toDateString(),
            'probation_end_date' => now()->addMonths(3)->toDateString(),
            'confirmation_date' => null,
            'termination_date' => null,
        ]);
    }

    public function probation(): static
    {
        return $this->state(fn (array $attributes) => [
            'employment_status' => 'probation',
            'hire_date' => now()->subMonth()->toDateString(),
            'probation_end_date' => now()->addMonths(2)->toDateString(),
            'confirmation_date' => null,
            'termination_date' => null,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'employment_status' => 'active',
            'hire_date' => now()->subYears(2)->toDateString(),
            'probation_end_date' => now()->subMonths(21)->toDateString(),
            'confirmation_date' => now()->subMonths(21)->toDateString(),
            'termination_date' => null,
        ]);
    }

    public function promoted(): static
    {
        return $this->state(function (array $attributes) {
            $newSalary = round(((float) ($attributes['base_annual_salary'] ?? 50000.00)) * 1.15, 2);

            return [
                'employment_status' => 'promoted',
                'base_annual_salary' => $newSalary,
                'hourly_cost_rate' => round(($newSalary / 2080) * 1.35, 2),
                'metadata' => array_merge($attributes['metadata'] ?? [], [
                    'last_promoted_at' => now()->toDateString(),
                ]),
            ];
        });
    }

    public function transferred(): static
    {
        return $this->state(fn (array $attributes) => [
            'employment_status' => 'transferred',
            'metadata' => array_merge($attributes['metadata'] ?? [], [
                'last_transferred_at' => now()->toDateString(),
            ]),
        ]);
    }

    public function terminated(): static
    {
        return $this->state(fn (array $attributes) => [
            'employment_status' => 'terminated',
            'termination_date' => now()->toDateString(),
            'termination_reason' => 'Voluntary resignation',
        ]);
    }

    public function onLeave(): static
    {
        return $this->state(fn (array $attributes) => [
            'employment_status' => 'on_leave',
        ]);
    }

    public function retired(): static
    {
        return $this->state(fn (array $attributes) => [
            'employment_status' => 'retired',
            'termination_date' => now()->toDateString(),
            'termination_reason' => 'Statutory retirement',
        ]);
    }
}
