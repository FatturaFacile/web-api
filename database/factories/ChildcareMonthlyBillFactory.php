<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Childcare\Models\MonthlyBill;

/**
 * @extends Factory<MonthlyBill>
 */
final class ChildcareMonthlyBillFactory extends Factory
{
    protected $model = MonthlyBill::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $attendedDays = fake()->numberBetween(12, 21);
        $gross = 1450 + $attendedDays * 9.5;
        $subsidy = min(400, $attendedDays * 20);

        return [
            'tenant_id' => Tenant::factory(),
            'child_id' => static fn (array $attributes): string => (string) ChildcareChildFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'period' => fake()->randomElement(['2026-09', '2026-10', '2026-11', '2026-12']),
            'attended_days' => $attendedDays,
            'meals' => $attendedDays,
            'gross_amount' => number_format($gross, 2, '.', ''),
            'subsidy_amount' => number_format($subsidy, 2, '.', ''),
            'net_amount' => number_format($gross - $subsidy, 2, '.', ''),
            'subsidy_rule_id' => static fn (array $attributes): string => (string) ChildcareSubsidyRuleFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'invoice_id' => null,
        ];
    }
}
