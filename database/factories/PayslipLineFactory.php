<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Payroll\Payslip;
use Modules\HumanResources\Models\Payroll\PayslipLine;

/**
 * @extends Factory<PayslipLine>
 */
class PayslipLineFactory extends Factory
{
    protected $model = PayslipLine::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'payslip_id' => Payslip::factory(),
            'tenant_id' => fn (array $attributes) => Payslip::withoutGlobalScopes()->find($attributes['payslip_id'])?->tenant_id,
            'wage_type_id' => null,
            'code' => '1000',
            'label' => 'Monthly salary',
            'line_type' => 'earning',
            'side' => 'employee',
            'base_rappen' => 800_000,
            'rate_ppm' => null,
            'amount_rappen' => 800_000,
            'sort_order' => 1,
        ];
    }
}
