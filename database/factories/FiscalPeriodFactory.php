<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
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
        $num = $this->faker->numberBetween(1, 12);

        return [
            'id' => (string) Str::uuid7(),
            // A real tenant, and the fiscal year of that tenant: an orphan tenant_id is refused by the tenant foreign key.
            'tenant_id' => static fn (): string => (string) Tenant::factory()->create()->id,
            'company_id' => (string) Str::uuid7(),
            'fiscal_year_id' => static fn (array $attributes): string => (string) (FiscalYear::withoutGlobalScopes()->where('tenant_id', $attributes['tenant_id'])->value('id')
                ?? FiscalYear::factory()->create(['tenant_id' => $attributes['tenant_id']])->id),
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
