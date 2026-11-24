<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Treasury\Models\BankAccount;

/**
 * @extends Factory<BankAccount>
 */
final class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition(): array
    {
        $tenantId = (string) Str::uuid7();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => (string) Str::uuid7(),
            'bank_name' => $this->faker->company().' Bank',
            'branch_name' => $this->faker->city().' Corporate Branch',
            'account_number' => 'ACC-'.$this->faker->numerify('#####'),
            'account_type' => 'OPERATING',
            'iban' => 'IT'.$this->faker->numerify('##').'X'.$this->faker->numerify('######################'),
            'bic_swift' => strtoupper($this->faker->lexify('????IT??')),
            'currency' => 'EUR',
            'opening_balance' => 100000.00,
            'current_balance' => 150000.00,
            'available_balance' => 200000.00,
            'overdraft_limit' => 50000.00,
            'credit_line_limit' => 100000.00,
            'is_default' => false,
            'is_pool_header' => false,
            'is_active' => true,
        ];
    }

    public function usd(): self
    {
        return $this->state(fn (array $attributes) => [
            'currency' => 'USD',
            'iban' => 'US'.$this->faker->numerify('##').$this->faker->numerify('######################'),
        ]);
    }

    public function poolHeader(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_pool_header' => true,
        ]);
    }
}
