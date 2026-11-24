<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\LedgerBudget;
use Modules\GeneralLedger\Models\LedgerBudgetLine;

/**
 * @extends Factory<LedgerBudgetLine>
 */
final class LedgerBudgetLineFactory extends Factory
{
    protected $model = LedgerBudgetLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'budget_id' => LedgerBudget::factory(),
            'tenant_id' => fn (array $attributes): string => (string) LedgerBudget::withoutGlobalScopes()->findOrFail($attributes['budget_id'])->tenant_id,
            'account_id' => fn (array $attributes): string => (string) ChartOfAccount::factory()->create(['tenant_id' => LedgerBudget::withoutGlobalScopes()->findOrFail($attributes['budget_id'])->tenant_id, 'company_id' => null])->id,
            'period_number' => 1,
            'amount' => '100.0000',
        ];
    }
}
