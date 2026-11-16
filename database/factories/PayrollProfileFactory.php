<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\Payroll\PayrollProfile;

/**
 * @extends Factory<PayrollProfile>
 */
class PayrollProfileFactory extends Factory
{
    protected $model = PayrollProfile::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'employee_id' => fn (array $attributes) => Employee::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            // Official sample number of the Central Compensation Office (valid EAN-13 check digit).
            'ahv_number' => '756.9217.0769.85',
            'work_canton' => 'ZH',
            'residence_canton' => 'ZH',
            'residence_country' => 'CH',
            'residence_municipality_bfs' => '261',
            'permit_type' => null,
            'civil_status' => 'single',
            'children_count' => 0,
            'church_tax' => false,
            'qst_tariff_code' => null,
            'qst_canton' => null,
            'bvg_insured' => true,
            'bvg_plan_code' => null,
            'uvg_class' => 'A1',
            'ktg_insured' => true,
            'metadata' => [],
        ];
    }

    /**
     * Foreign worker taxed at source (permit B), e.g. canton TI tariff A0N.
     */
    public function withholdingTax(string $canton = 'TI', string $tariffCode = 'A0N', bool $churchTax = false): static
    {
        return $this->state(fn (array $attributes) => [
            'work_canton' => $canton,
            'residence_canton' => $canton,
            'permit_type' => 'B',
            'qst_tariff_code' => $tariffCode,
            'qst_canton' => $canton,
            'church_tax' => $churchTax,
        ]);
    }

    public function withoutAhvNumber(): static
    {
        return $this->state(fn (array $attributes) => ['ahv_number' => null]);
    }
}
