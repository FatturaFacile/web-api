<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\PayrollRunEvent;

/**
 * @extends Factory<PayrollRunEvent>
 */
final class PayrollRunEventFactory extends Factory
{
    protected $model = PayrollRunEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'payroll_run_id' => static fn (array $attributes): string => (string) PayrollRun::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'action' => PayrollRunEvent::Calculated,
            'reason' => null,
            'totals' => ['payslips' => 9, 'gross_salary' => '58500.00', 'net_salary' => '51187.50', 'employer_contributions' => '8775.00'],
            'actor_id' => null,
            'actor_name' => fake()->name(),
            'occurred_at' => '2026-11-25 16:00:00',
        ];
    }
}
