<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Models\SalaryCertificate;

/**
 * @extends Factory<SalaryCertificate>
 */
final class SalaryCertificateFactory extends Factory
{
    protected $model = SalaryCertificate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'employee_id' => static fn (array $attributes): string => (string) Employee::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'year' => 2026,
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'box1_salary' => '84500.00',
            'box2_benefits' => '0.00',
            'box3_irregular' => '2000.00',
            'box4_capital' => '0.00',
            'box7_other' => '0.00',
            'box8_gross' => '86500.00',
            'box9_social_contributions' => '5601.00',
            'box10_pension' => '4325.00',
            'box11_net' => '76574.00',
            'box12_withholding_tax' => '0.00',
            'box13_expenses' => '0.00',
            'remarks' => null,
            'generated_at' => '2027-01-20 09:00:00',
        ];
    }
}
