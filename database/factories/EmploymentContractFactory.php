<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmploymentContract;

/**
 * @extends Factory<EmploymentContract>
 */
class EmploymentContractFactory extends Factory
{
    protected $model = EmploymentContract::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'employee_id' => function (array $attrs) {
                return Employee::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'contract_number' => 'CNT-'.strtoupper(Str::random(8)),
            'contract_type' => 'permanent',
            'collective_agreement' => 'CCNL Metalmeccanici',
            'job_title' => 'Senior Systems Engineer',
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => null,
            'weekly_hours' => 40.00,
            'fte_percentage' => 100.00,
            'base_annual_salary' => 52000.00,
            'hourly_rate' => 25.00,
            'loaded_hourly_cost' => 33.75,
            'currency' => 'EUR',
            'status' => 'active',
            'is_active' => true,
            'is_locked' => false,
            'terms' => ['notice_period_days' => 60],
        ];
    }
}
