<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipLine;

/**
 * @extends Factory<PayslipLine>
 */
final class PayslipLineFactory extends Factory
{
    protected $model = PayslipLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'payslip_id' => static fn (array $attributes): string => (string) Payslip::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'wage_type_code' => '1000',
            'description' => 'Salario mensile',
            'kind' => 'Earning',
            'quantity' => '1.0000',
            'rate' => '6500.0000',
            'amount' => '6500.00',
            'certificate_box' => '1',
            'sort_order' => 10,
        ];
    }
}
