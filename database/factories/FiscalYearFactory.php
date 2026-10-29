<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\FiscalYear;

/**
 * @extends Factory<FiscalYear>
 */
final class FiscalYearFactory extends Factory
{
    protected $model = FiscalYear::class;

    public function definition(): array
    {
        $year = $this->faker->numberBetween(2025, 2030);

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) Str::uuid7(),
            'company_id' => (string) Str::uuid7(),
            'year_code' => 'FY'.$year.'-'.Str::random(4),
            'start_date' => "{$year}-01-01",
            'end_date' => "{$year}-12-31",
            'status' => 'open',
            'is_locked' => false,
        ];
    }

    public function closed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
            'is_locked' => true,
            'closed_at' => now(),
        ]);
    }
}
