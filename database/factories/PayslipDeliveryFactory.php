<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipDelivery;

/**
 * @extends Factory<PayslipDelivery>
 */
final class PayslipDeliveryFactory extends Factory
{
    protected $model = PayslipDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'payslip_id' => static fn (array $attributes): string => (string) Payslip::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'employee_id' => static fn (array $attributes): string => (string) Payslip::query()->withoutGlobalScopes()->whereKey($attributes['payslip_id'])->value('employee_id'),
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => '2026-12-31 23:59:59',
            'opened_at' => null,
            'failed_attempts' => 0,
        ];
    }
}
