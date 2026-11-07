<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Payroll\Models\PayrollWithholdingStatement;

/**
 * @extends Factory<PayrollWithholdingStatement>
 */
final class PayrollWithholdingStatementFactory extends Factory
{
    protected $model = PayrollWithholdingStatement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = [
            'lines' => [
                [
                    'employee_id' => (string) Str::uuid7(),
                    'employee_name' => fake()->name(),
                    'tariff_code' => 'A0N',
                    'taxable_income' => '6500.00',
                    'rate_percent' => '8.1500',
                    'tax' => '529.75',
                ],
            ],
            'corrections' => [],
        ];

        return [
            'tenant_id' => Tenant::factory(),
            'canton' => 'TI',
            'year' => 2026,
            'period_type' => PayrollWithholdingStatement::Quarterly,
            'period' => fake()->numberBetween(1, 4),
            'content' => $content,
            'content_hash' => hash('sha256', (string) json_encode($content, JSON_THROW_ON_ERROR)),
            'total_tax' => '529.75',
            'closed_by' => null,
            'closed_at' => '2026-10-05 10:00:00',
        ];
    }
}
