<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Models\ShortTimeClaim;
use Modules\Payroll\Models\ShortTimeLine;

/**
 * @extends Factory<ShortTimeLine>
 */
final class ShortTimeLineFactory extends Factory
{
    protected $model = ShortTimeLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'short_time_claim_id' => static fn (array $attributes): string => (string) ShortTimeClaim::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'employee_id' => static fn (array $attributes): string => (string) Employee::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'is_eligible' => true,
            'ineligibility_reason' => null,
            'target_hours' => '168.00',
            'worked_hours' => '126.00',
            'absence_hours' => '0.00',
            'overtime_deducted_hours' => '0.00',
            'lost_hours' => '42.00',
            'waiting_hours' => '8.40',
            'compensable_hours' => '33.60',
            'insured_monthly_salary' => '6500.00',
            'lost_earnings' => '1300.00',
            'compensation' => '1040.00',
            'wage_reduction' => '260.00',
            'employer_refund' => '1144.00',
        ];
    }
}
