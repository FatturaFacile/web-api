<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BankReconciliationRule;
use App\Models\BankStatementLine;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankReconciliationRule>
 */
final class BankReconciliationRuleFactory extends Factory
{
    protected $model = BankReconciliationRule::class;

    /**
     * A bank charges rule recognised by the description.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Rule '.fake()->unique()->numberBetween(1, 999999),
            'priority' => 100,
            'is_active' => true,
            'direction' => BankStatementLine::DEBIT,
            'amount_tolerance' => 0,
            'description_contains' => 'COMMISSIONI',
            'category' => BankStatementLine::CATEGORY_BANK_EXPENSE,
        ];
    }
}
