<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\AccountsPayable\DTOs\SkontoTerms;
use Modules\AccountsPayable\Services\SkontoCalculator;

$invoiceDate = CarbonImmutable::parse('2027-03-01');
$terms = new SkontoTerms('2.00', 10, 30, SkontoTerms::SourceSupplierAgreement, 'CNT-1');

it('gives the discount while the deadline has not passed', function () use ($invoiceDate, $terms): void {
    $result = (new SkontoCalculator)->calculate($terms, $invoiceDate, '1000.00', false, false, CarbonImmutable::parse('2027-03-04'));

    expect($result->eligible)->toBeTrue()
        ->and($result->percentage)->toBe('2.00')
        ->and($result->deadlineDate)->toBe('2027-03-11')
        ->and($result->daysRemaining)->toBe(7)
        ->and($result->savingsAmount)->toBe('20.00')
        ->and($result->netPayableWithSkonto)->toBe('980.00')
        ->and($result->standardAmount)->toBe('1000.00')
        ->and($result->termsSource)->toBe('supplier_agreement')
        ->and($result->netDays)->toBe(30);
});

it('still gives the discount on the deadline day, not the day after', function () use ($invoiceDate, $terms): void {
    $calculator = new SkontoCalculator;
    $onDeadline = $calculator->calculate($terms, $invoiceDate, '500.00', false, false, CarbonImmutable::parse('2027-03-11'));
    $after = $calculator->calculate($terms, $invoiceDate, '500.00', false, false, CarbonImmutable::parse('2027-03-12'));

    expect($onDeadline->eligible)->toBeTrue()
        ->and($onDeadline->daysRemaining)->toBe(0)
        ->and($onDeadline->savingsAmount)->toBe('10.00')
        ->and($after->eligible)->toBeFalse()
        ->and($after->daysRemaining)->toBe(-1)
        ->and($after->savingsAmount)->toBe('0.00')
        ->and($after->netPayableWithSkonto)->toBe('500.00');
});

it('rounds the discount to the cent', function () use ($invoiceDate): void {
    $result = (new SkontoCalculator)->calculate(new SkontoTerms('3.00', 8), $invoiceDate, '33.33', false, false, $invoiceDate);

    expect($result->savingsAmount)->toBe('1.00')
        ->and($result->netPayableWithSkonto)->toBe('32.33');
});

it('gives no discount on a credit note, on a partly paid invoice or without terms', function () use ($invoiceDate, $terms): void {
    $calculator = new SkontoCalculator;

    expect($calculator->calculate($terms, $invoiceDate, '100.00', true, false, $invoiceDate)->eligible)->toBeFalse()
        ->and($calculator->calculate($terms, $invoiceDate, '100.00', false, true, $invoiceDate)->eligible)->toBeFalse()
        ->and($calculator->calculate(null, $invoiceDate, '100.00', false, false, $invoiceDate)->toArray())->toMatchArray([
            'skonto_eligible' => false,
            'skonto_percentage' => null,
            'skonto_deadline_date' => null,
            'skonto_days_remaining' => null,
            'skonto_savings_amount' => '0.00',
            'net_payable_with_skonto' => '100.00',
            'standard_amount' => '100.00',
        ]);
});

it('ignores terms that cannot be applied', function () use ($invoiceDate): void {
    $calculator = new SkontoCalculator;

    expect($calculator->calculate(new SkontoTerms('0.00', 10), $invoiceDate, '100.00', false, false, $invoiceDate)->eligible)->toBeFalse()
        ->and($calculator->calculate(new SkontoTerms('120.00', 10), $invoiceDate, '100.00', false, false, $invoiceDate)->eligible)->toBeFalse();
});

it('exposes the fields of the API', function () use ($invoiceDate, $terms): void {
    $fields = (new SkontoCalculator)->calculate($terms, $invoiceDate, '1000.00', false, false, $invoiceDate)->toArray();

    expect(array_keys($fields))->toBe([
        'skonto_eligible', 'skonto_percentage', 'skonto_deadline_date', 'skonto_days_remaining', 'skonto_savings_amount',
        'net_payable_with_skonto', 'standard_amount', 'skonto_terms_source', 'skonto_net_days',
    ]);
});
