<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\LedgerBudget;

/**
 * @extends Factory<LedgerBudget>
 */
final class LedgerBudgetFactory extends Factory
{
    protected $model = LedgerBudget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fiscal_year_id' => FiscalYear::factory()->state(['tenant_id' => Tenant::factory(), 'company_id' => null]),
            'tenant_id' => fn (array $attributes): string => (string) FiscalYear::withoutGlobalScopes()->findOrFail($attributes['fiscal_year_id'])->tenant_id,
            'name' => $this->faker->unique()->words(2, true),
            'version' => 1,
            'status' => 'draft',
        ];
    }
}
