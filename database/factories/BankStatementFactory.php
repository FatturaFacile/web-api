<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankStatement;

/**
 * @extends Factory<BankStatement>
 */
final class BankStatementFactory extends Factory
{
    protected $model = BankStatement::class;

    public function definition(): array
    {
        $account = BankAccount::first() ?? BankAccountFactory::new()->create();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $account->tenant_id,
            'company_id' => $account->company_id,
            'bank_account_id' => $account->id,
            'statement_number' => 'STMT-'.date('Ymd').'-'.strtoupper(Str::random(4)),
            'statement_date' => date('Y-m-d'),
            'opening_balance' => 100000.00,
            'closing_balance' => 115000.00,
            'currency' => $account->currency ?? 'EUR',
            'account_iban' => $account->iban ?? 'IT60X0542811101000000123456',
            'source_format' => 'CAMT_053',
            'format' => 'CAMT_053',
            'total_entries' => 1,
            'status' => 'imported',
            'content_hash' => hash('sha256', Str::random(32)),
            'file_sha256' => hash('sha256', Str::random(32)),
            'reconciliation_status' => 'unreconciled',
        ];
    }
}
