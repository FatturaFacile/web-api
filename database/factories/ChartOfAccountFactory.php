<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\ChartOfAccount;

/**
 * @extends Factory<ChartOfAccount>
 */
final class ChartOfAccountFactory extends Factory
{
    protected $model = ChartOfAccount::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) Str::uuid7(),
            'company_id' => (string) Str::uuid7(),
            'account_code' => (string) $this->faker->unique()->numberBetween(1000, 7999),
            'code' => fn (array $attributes) => $attributes['account_code'],
            'standard' => 'it_general_ledger',
            'type' => 'asset',
            'name' => $this->faker->words(3, true),
            'account_class' => 'asset',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'currency' => 'EUR',
            'is_reconciliation_account' => false,
            'allows_manual_posting' => true,
            'is_active' => true,
        ];
    }
}
