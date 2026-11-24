<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Payroll\Models\PayrollLegalParameter;

/**
 * @extends Factory<PayrollLegalParameter>
 */
final class PayrollLegalParameterFactory extends Factory
{
    protected $model = PayrollLegalParameter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'topic' => 'short_time_work',
            'year' => 2026,
            'parameters' => [
                'compensation_percent' => '80',
                'minimum_loss_percent' => '10',
                'waiting_days_per_period' => '1',
                'maximum_periods' => 12,
                'framework_months' => 24,
                'claim_deadline_months' => 3,
                'notice_days' => 10,
                'urgent_notice_days' => 3,
                'maximum_insured_monthly_salary' => '12350',
            ],
            'source' => 'SECO, Kurzarbeitsentschädigung, Information für Arbeitgeber, edizione 2026; AVIG SR 837.0.',
        ];
    }
}
