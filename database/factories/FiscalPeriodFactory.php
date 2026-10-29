<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;

/**
 * @extends Factory<FiscalPeriod>
 */
final class FiscalPeriodFactory extends Factory
{
    protected $model = FiscalPeriod::class;

    public function definition(): array
    {
        $tenantId = (string) Str::uuid7();
        $year = FiscalYear::first() ?? FiscalYear::factory()->create(['tenant_id' => $tenantId]);
        $num = $this->faker->numberBetween(1, 12);

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => (string) Str::uuid7(),
            'fiscal_year_id' => $year->id,
            'period_number' => $num,
            'period_name' => "Period {$num}",
            'start_date' => date('Y-m-01'),
            'end_date' => date('Y-m-t'),
            'status' => 'open',
        ];
    }

    public function closed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
        ]);
    }
}
