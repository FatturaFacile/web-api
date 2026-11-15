<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\HumanResources\Enums\Payroll\PayrollRunStatus;
use Modules\HumanResources\Models\Payroll\PayrollRun;

/**
 * @extends Factory<PayrollRun>
 */
class PayrollRunFactory extends Factory
{
    protected $model = PayrollRun::class;

    public function definition(): array
    {
        $periodStart = Carbon::now()->startOfMonth();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'run_number' => 'PR-'.$periodStart->format('Ym').'-'.strtoupper(Str::random(5)),
            'period_year' => (int) $periodStart->format('Y'),
            'period_month' => (int) $periodStart->format('n'),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodStart->copy()->endOfMonth()->toDateString(),
            'payment_date' => $periodStart->copy()->day(25)->toDateString(),
            'status' => PayrollRunStatus::Draft,
            'currency' => 'CHF',
            'employee_ids' => null,
            'inputs' => [],
        ];
    }

    public function forPeriod(int $year, int $month): static
    {
        return $this->state(function (array $attributes) use ($year, $month): array {
            $periodStart = Carbon::parse(sprintf('%04d-%02d-01', $year, $month))->startOfDay();

            return [
                'run_number' => sprintf('PR-%04d%02d-', $year, $month).strtoupper(Str::random(5)),
                'period_year' => $year,
                'period_month' => $month,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodStart->copy()->endOfMonth()->toDateString(),
                'payment_date' => $periodStart->copy()->day(25)->toDateString(),
            ];
        });
    }

    public function calculated(): static
    {
        return $this->state(fn (array $attributes) => ['status' => PayrollRunStatus::Calculated, 'calculated_at' => now()]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => ['status' => PayrollRunStatus::Approved, 'calculated_at' => now(), 'approved_at' => now()]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => PayrollRunStatus::Closed, 'calculated_at' => now(), 'approved_at' => now(), 'closed_at' => now()]);
    }
}
