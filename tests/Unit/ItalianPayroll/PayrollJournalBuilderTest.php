<?php

declare(strict_types=1);

use Modules\ItalianPayroll\Domain\Enums\GlRole;
use Modules\ItalianPayroll\Domain\Exceptions\ItalianPayrollException;
use Modules\ItalianPayroll\Infrastructure\Services\PayrollJournalBuilder;
use Modules\Payroll\Calculation\Dec;

function pipSummary(array $override = []): array
{
    return $override + [
        'gross' => '10000.00', 'inps_employee' => '919.00', 'inps_employer' => '3000.00', 'irpef' => '1500.00', 'addizionali' => '120.00',
        'tfr_accrual' => '740.00', 'tfr_fund' => '740.00', 'net' => '7461.00',
    ];
}

function pipAccounts(): array
{
    $accounts = [];

    foreach (GlRole::cases() as $role) {
        $accounts[$role->value] = "account-{$role->value}";
    }

    return $accounts;
}

it('builds a balanced entry with the dare and avere of the schema', function (): void {
    $lines = (new PayrollJournalBuilder)->lines(pipSummary(), pipAccounts());
    $debit = Dec::sum(array_column($lines, 'debit_amount'));
    $credit = Dec::sum(array_column($lines, 'credit_amount'));

    expect($lines)->toHaveCount(8)
        ->and(Dec::cmp($debit, $credit))->toBe(0)
        ->and(Dec::round($debit))->toBe('13740.00');

    $byAccount = array_column($lines, null, 'account_id');
    expect($byAccount['account-gross_wages']['debit_amount'])->toBe('10000.00')
        ->and($byAccount['account-net_payable']['credit_amount'])->toBe('7461.00')
        ->and(Dec::round($byAccount['account-inps_payable']['credit_amount']))->toBe('3919.00');
});

it('refuses a summary whose net does not reconcile with gross minus deductions', function (): void {
    (new PayrollJournalBuilder)->lines(pipSummary(['net' => '7000.00']), pipAccounts());
})->throws(ItalianPayrollException::class, 'non quadra');

it('refuses a TFR accrual that differs from the TFR fund credit', function (): void {
    (new PayrollJournalBuilder)->lines(pipSummary(['tfr_fund' => '700.00']), pipAccounts());
})->throws(ItalianPayrollException::class, 'TFR');

it('refuses to post when a role has no account', function (): void {
    $accounts = pipAccounts();
    unset($accounts[GlRole::IrpefPayable->value]);

    (new PayrollJournalBuilder)->lines(pipSummary(), $accounts);
})->throws(ItalianPayrollException::class, 'irpef_payable');
