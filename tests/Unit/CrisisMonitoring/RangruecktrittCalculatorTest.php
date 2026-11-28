<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\CrisisMonitoring\Domain\Swiss\RangruecktrittCalculator;

/**
 * @param  array<string, mixed>  $overrides
 * @return array{principal: string, interest_rate: string, interest_treatment: string, scope: string, status: string, effective_from: CarbonImmutable, deferral_end_date: CarbonImmutable|null}
 */
function crisisLoan(array $overrides = []): array
{
    return array_merge([
        'principal' => '100000', 'interest_rate' => '5', 'interest_treatment' => 'deferred', 'scope' => 'principal_and_interest', 'status' => 'active',
        'effective_from' => CarbonImmutable::parse('2030-01-01'), 'deferral_end_date' => null,
    ], $overrides);
}

it('adds the accrued and the future contractual interest to the subordinated claim', function (): void {
    $claim = (new RangruecktrittCalculator)->claim(crisisLoan(), CarbonImmutable::parse('2030-07-01'), 12);

    // 181 days accrued and 365 days to the end of the horizon, simple interest at 5 % on 100,000.
    expect($claim['accrued_interest'])->toBe('2479.45')
        ->and($claim['future_interest'])->toBe('5000.00')
        ->and($claim['claim'])->toBe('107479.45');
});

it('stops the future interest at the end of the deferral when it comes before the horizon', function (): void {
    $claim = (new RangruecktrittCalculator)->claim(crisisLoan(['deferral_end_date' => CarbonImmutable::parse('2030-12-31')]), CarbonImmutable::parse('2030-07-01'), 12);

    expect($claim['future_interest'])->toBe('2506.85')->and($claim['claim'])->toBe('104986.30');
});

it('counts only the principal when the interest is paid currently or not subordinated', function (): void {
    $calculator = new RangruecktrittCalculator;
    $paid = $calculator->claim(crisisLoan(['interest_treatment' => 'paid_current']), CarbonImmutable::parse('2030-07-01'), 12);
    $principalOnly = $calculator->claim(crisisLoan(['scope' => 'principal']), CarbonImmutable::parse('2030-07-01'), 12);

    expect($paid['claim'])->toBe('100000.00')->and($paid['accrued_interest'])->toBe('0.00')
        ->and($principalOnly['claim'])->toBe('100000.00');
});

it('covers only the active agreements that are in force at the date', function (): void {
    $asOf = CarbonImmutable::parse('2030-07-01');
    $result = (new RangruecktrittCalculator)->covered([
        crisisLoan(['interest_treatment' => 'paid_current']),
        crisisLoan(['interest_treatment' => 'paid_current', 'principal' => '50000', 'status' => 'draft']),
        crisisLoan(['interest_treatment' => 'paid_current', 'principal' => '30000', 'status' => 'released']),
        crisisLoan(['interest_treatment' => 'paid_current', 'principal' => '20000', 'effective_from' => CarbonImmutable::parse('2030-08-01')]),
        crisisLoan(['interest_treatment' => 'paid_current', 'principal' => '10000', 'deferral_end_date' => CarbonImmutable::parse('2030-06-30')]),
        crisisLoan(['interest_treatment' => 'paid_current', 'principal' => '5000', 'deferral_end_date' => CarbonImmutable::parse('2030-07-01')]),
    ], $asOf, 12);

    expect($result['claims'])->toBe('105000.00')->and($result['loans'])->toHaveCount(6);
});

it('computes the claim and the principal still to be subordinated for a new agreement', function (): void {
    $result = (new RangruecktrittCalculator)->required('200000', '107479.45', 12, '5');

    // 92,520.55 to cover; principal x 1.05 must reach it: 88,114.81 rounded up to the cent.
    expect($result['remaining_shortfall'])->toBe('92520.55')
        ->and($result['required_claim_amount'])->toBe('92520.55')
        ->and($result['required_principal_amount'])->toBe('88114.81')
        ->and($result['assumed_rate'])->toBe('5.00');
});

it('needs nothing more when the subordinated claims already cover the shortfall', function (): void {
    $result = (new RangruecktrittCalculator)->required('100000', '120000', 12, '5');

    expect($result['remaining_shortfall'])->toBe('0.00')->and($result['required_principal_amount'])->toBe('0.00');
});

it('needs the claim as principal when the agreement carries no interest', function (): void {
    $result = (new RangruecktrittCalculator)->required('50000', '0', 24, '0');

    expect($result['required_principal_amount'])->toBe('50000.00');
});
