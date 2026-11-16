<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Database\Seeders\SwissPayrollReferenceSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Payroll\PayrollRateSet;

/**
 * @extends Factory<PayrollRateSet>
 */
class PayrollRateSetFactory extends Factory
{
    protected $model = PayrollRateSet::class;

    public function definition(): array
    {
        $year = (int) now()->format('Y');

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'CH-'.$year.'-'.strtoupper(Str::random(4)),
            'name' => "Swiss rate set {$year}",
            'year' => $year,
            'valid_from' => "{$year}-01-01",
            'valid_to' => "{$year}-12-31",
            'rates' => SwissPayrollReferenceSeeder::indicativeRates($year),
            'is_active' => true,
        ];
    }

    public function forYear(int $year): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'CH-'.$year.'-'.strtoupper(Str::random(4)),
            'name' => "Swiss rate set {$year}",
            'year' => $year,
            'valid_from' => "{$year}-01-01",
            'valid_to' => "{$year}-12-31",
            'rates' => SwissPayrollReferenceSeeder::indicativeRates($year),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides  dotted paths, e.g. ['alv.solidarity_employee' => '0.50']
     */
    public function withRates(array $overrides): static
    {
        return $this->state(function (array $attributes) use ($overrides): array {
            /** @var array<string, mixed> $rates */
            $rates = $attributes['rates'];

            foreach ($overrides as $path => $value) {
                data_set($rates, $path, $value);
            }

            return ['rates' => $rates];
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
