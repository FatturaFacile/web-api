<?php

declare(strict_types=1);

use Modules\CrisisMonitoring\Domain\Ccii\PublicCreditorMonitor;

/**
 * The statutory rows of the seeder (Art. 25-novies CCII).
 *
 * @return list<array<string, mixed>>
 */
function crisisRules(): array
{
    $base = ['legal_form' => null, 'overdue_days' => null, 'absolute_threshold' => null, 'absolute_threshold_no_employees' => null, 'absolute_always_threshold' => null, 'percent_threshold' => null, 'percent_basis' => null, 'combine' => 'and', 'warning_ratio' => '0.70'];
    $rows = [
        ['creditor_code' => 'payroll', 'overdue_days' => 30, 'percent_threshold' => '50.00', 'percent_basis' => 'monthly_payroll'],
        ['creditor_code' => 'supplier', 'overdue_days' => 90, 'percent_threshold' => '100.00', 'percent_basis' => 'non_overdue_suppliers'],
        ['creditor_code' => 'inps', 'overdue_days' => 90, 'absolute_threshold' => '15000.00', 'absolute_threshold_no_employees' => '5000.00', 'percent_threshold' => '30.00', 'percent_basis' => 'prior_year_contributions'],
        ['creditor_code' => 'inail', 'overdue_days' => 90, 'absolute_threshold' => '5000.00'],
        ['creditor_code' => 'ade_vat', 'absolute_threshold' => '5000.00', 'absolute_always_threshold' => '20000.00', 'percent_threshold' => '10.00', 'percent_basis' => 'prior_year_turnover'],
        ['creditor_code' => 'ader', 'legal_form' => 'sole_proprietorship', 'absolute_threshold' => '100000.00'],
        ['creditor_code' => 'ader', 'legal_form' => 'partnership', 'absolute_threshold' => '200000.00'],
        ['creditor_code' => 'ader', 'legal_form' => 'corporation', 'absolute_threshold' => '500000.00'],
    ];

    return array_map(static fn (array $row): array => $row + $base + ['label_key' => 'crisisApi.creditor.'.$row['creditor_code'], 'description_key' => 'crisisApi.creditor.'.$row['creditor_code'].'.description'], $rows);
}

/**
 * @param  array<string, string>  $measured
 * @param  array<string, string>  $bases
 * @return array<string, array<string, mixed>>
 */
function crisisCreditors(array $measured, array $bases = [], string $legalForm = 'corporation', int $employees = 10): array
{
    $result = (new PublicCreditorMonitor)->evaluate(crisisRules(), [
        'legal_form' => $legalForm,
        'employees_count' => $employees,
        'measured' => $measured,
        'bases' => $bases + ['monthly_payroll' => '0', 'non_overdue_suppliers' => '0', 'prior_year_contributions' => '0', 'prior_year_turnover' => '0'],
    ]);

    return array_column($result, null, 'code');
}

it('monitors the six qualified creditors with one result each', function (): void {
    $result = crisisCreditors([]);

    expect(array_keys($result))->toBe(['payroll', 'supplier', 'inps', 'inail', 'ade_vat', 'ader'])
        ->and(array_unique(array_column($result, 'status')))->toBe(['green']);
});

it('breaches the INPS rule only when both the absolute amount and the share of the prior-year contributions are exceeded', function (): void {
    $bases = ['prior_year_contributions' => '100000'];

    $onlyAbsolute = crisisCreditors(['inps' => '20000'], $bases)['inps'];
    $both = crisisCreditors(['inps' => '35000'], $bases)['inps'];

    expect($onlyAbsolute['breached'])->toBeFalse()->and($onlyAbsolute['status'])->toBe('green')
        ->and($both['breached'])->toBeTrue()->and($both['status'])->toBe('red')
        ->and($both['limit'])->toBe('30000.00')
        ->and($both['progress_percent'])->toBe(117)
        ->and($both['overdue_days'])->toBe(90);
});

it('uses the lower INPS absolute limit without employees', function (): void {
    $withEmployees = crisisCreditors(['inps' => '6000'], ['prior_year_contributions' => '10000'], 'corporation', 3)['inps'];
    $without = crisisCreditors(['inps' => '6000'], ['prior_year_contributions' => '10000'], 'sole_proprietorship', 0)['inps'];

    expect($withEmployees['breached'])->toBeFalse()->and($without['breached'])->toBeTrue();
});

it('breaches INAIL above 5000 and warns at 70 percent of it', function (): void {
    expect(crisisCreditors(['inail' => '5001'])['inail']['status'])->toBe('red')
        ->and(crisisCreditors(['inail' => '4000'])['inail']['status'])->toBe('yellow')
        ->and(crisisCreditors(['inail' => '4000'])['inail']['progress_percent'])->toBe(80)
        ->and(crisisCreditors(['inail' => '3000'])['inail']['status'])->toBe('green');
});

it('breaches the VAT rule on 5000 plus 10 percent of the turnover, or on 20000 alone', function (): void {
    $bases = ['prior_year_turnover' => '100000'];

    expect(crisisCreditors(['ade_vat' => '6000'], $bases)['ade_vat']['breached'])->toBeFalse()
        ->and(crisisCreditors(['ade_vat' => '10001'], $bases)['ade_vat']['breached'])->toBeTrue()
        ->and(crisisCreditors(['ade_vat' => '21000'], ['prior_year_turnover' => '10000000'])['ade_vat']['breached'])->toBeTrue();
});

it('picks the AdER limit of the legal form', function (): void {
    expect(crisisCreditors(['ader' => '400000'], [], 'corporation')['ader']['status'])->toBe('yellow')
        ->and(crisisCreditors(['ader' => '250000'], [], 'partnership')['ader']['breached'])->toBeTrue()
        ->and(crisisCreditors(['ader' => '250000'], [], 'partnership')['ader']['limit'])->toBe('200000.00')
        ->and(crisisCreditors(['ader' => '120000'], [], 'sole_proprietorship')['ader']['breached'])->toBeTrue();
});

it('breaches payroll arrears above half of the monthly payroll and supplier arrears above the non-overdue payables', function (): void {
    $payroll = crisisCreditors(['payroll' => '6000'], ['monthly_payroll' => '10000'])['payroll'];
    $supplier = crisisCreditors(['supplier' => '15000'], ['non_overdue_suppliers' => '10000'])['supplier'];
    $fine = crisisCreditors(['supplier' => '5000'], ['non_overdue_suppliers' => '10000'])['supplier'];

    expect($payroll['breached'])->toBeTrue()->and($payroll['limit'])->toBe('5000.00')
        ->and($supplier['breached'])->toBeTrue()
        ->and($fine['breached'])->toBeFalse()->and($fine['progress_percent'])->toBe(50);
});

it('treats any arrear as a breach when the percentage basis is zero', function (): void {
    expect(crisisCreditors(['payroll' => '100'])['payroll']['breached'])->toBeTrue();
});

it('lets a combine of or breach on either limit', function (): void {
    $rule = ['creditor_code' => 'inail', 'legal_form' => null, 'label_key' => 'k', 'description_key' => 'd', 'overdue_days' => 90, 'absolute_threshold' => '5000.00', 'percent_threshold' => '10.00', 'percent_basis' => 'prior_year_turnover', 'combine' => 'or'];
    $result = (new PublicCreditorMonitor)->evaluate([$rule], ['legal_form' => 'corporation', 'employees_count' => 1, 'measured' => ['inail' => '1500'], 'bases' => ['prior_year_turnover' => '10000']]);

    expect($result[0]['breached'])->toBeTrue()->and($result[0]['limit'])->toBe('1000.00');
});
