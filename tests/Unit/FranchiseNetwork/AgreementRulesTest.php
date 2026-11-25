<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\FranchiseNetwork\Domain\Support\AgreementRules;
use Modules\FranchiseNetwork\Domain\Support\EntryFeeSchedule;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function frnAgreement(array $overrides = []): array
{
    return [
        'jurisdiction' => 'IT',
        'start_date' => '2028-01-01',
        'end_date' => '2030-12-31',
        'term_months' => 36,
        'signed_on' => null,
        'disclosure_delivered_on' => null,
        'entry_fee' => 0,
        'entry_fee_deferral_months' => 0,
        'guarantee_type' => 'none',
        'guarantee_expires_on' => null,
        'payment_method' => 'bank_transfer',
        'mandate_reference' => null,
        'renewal_notice_days' => 180,
        ...$overrides,
    ];
}

/**
 * @param  list<array{code: string, severity: string, params: array<string, scalar>}>  $findings
 * @return list<string>
 */
function frnCodes(array $findings): array
{
    return array_column($findings, 'code');
}

$today = CarbonImmutable::parse('2028-06-01');

it('accepts an Italian agreement of at least three years', function () use ($today): void {
    expect(AgreementRules::evaluate(frnAgreement(), $today))->toBe([]);
});

it('warns when an Italian term is below the three years of L. 129/2004', function () use ($today): void {
    $findings = AgreementRules::evaluate(frnAgreement(['term_months' => 24, 'end_date' => '2029-12-31']), $today);

    expect(frnCodes($findings))->toBe(['term_below_statutory_minimum'])
        ->and($findings[0]['severity'])->toBe('warning')
        ->and($findings[0]['params'])->toBe(['months' => 24, 'minimum' => 36]);
});

it('does not apply the Italian minimum term to a Swiss agreement', function () use ($today): void {
    $findings = AgreementRules::evaluate(frnAgreement(['jurisdiction' => 'CH', 'term_months' => 12, 'end_date' => '2028-12-31']), $today);

    expect($findings)->toBe([]);
});

it('warns when the pre-contract disclosure came less than thirty days before the signature', function () use ($today): void {
    $short = AgreementRules::evaluate(frnAgreement(['signed_on' => '2028-01-01', 'disclosure_delivered_on' => '2027-12-20']), $today);
    $missing = AgreementRules::evaluate(frnAgreement(['signed_on' => '2028-01-01']), $today);
    $enough = AgreementRules::evaluate(frnAgreement(['signed_on' => '2028-01-01', 'disclosure_delivered_on' => '2027-11-01']), $today);

    expect(frnCodes($short))->toBe(['disclosure_period_too_short'])
        ->and(frnCodes($missing))->toBe(['disclosure_period_too_short'])
        ->and($enough)->toBe([]);
});

it('flags an entry fee that is not deferred and a deferral longer than the term', function () use ($today): void {
    $plain = AgreementRules::evaluate(frnAgreement(['entry_fee' => 20000]), $today);
    $long = AgreementRules::evaluate(frnAgreement(['entry_fee' => 20000, 'entry_fee_deferral_months' => 40]), $today);

    expect(frnCodes($plain))->toBe(['entry_fee_not_deferred'])
        ->and(frnCodes($long))->toBe(['entry_fee_deferral_exceeds_term'])
        ->and($long[0]['severity'])->toBe('error');
});

it('asks for a mandate when the agreement collects by direct debit, and a Swiss account for LSV+', function () use ($today): void {
    $sdd = AgreementRules::evaluate(frnAgreement(['payment_method' => 'sepa_sdd']), $today);
    $lsv = AgreementRules::evaluate(frnAgreement(['payment_method' => 'lsv_plus', 'mandate_reference' => 'LSV-1']), $today);
    $signed = AgreementRules::evaluate(frnAgreement(['payment_method' => 'sepa_sdd', 'mandate_reference' => 'SDD-1']), $today);

    expect(frnCodes($sdd))->toBe(['mandate_missing'])
        ->and(frnCodes($lsv))->toBe(['lsv_plus_requires_swiss_account'])
        ->and($signed)->toBe([]);
});

it('monitors the expiry of the guarantee', function () use ($today): void {
    $guarantee = ['guarantee_type' => 'bank_guarantee'];

    $expired = AgreementRules::evaluate(frnAgreement([...$guarantee, 'guarantee_expires_on' => '2028-05-31']), $today);
    $expiring = AgreementRules::evaluate(frnAgreement([...$guarantee, 'guarantee_expires_on' => '2028-07-01']), $today);
    $unknown = AgreementRules::evaluate(frnAgreement($guarantee), $today);
    $shortLived = AgreementRules::evaluate(frnAgreement([...$guarantee, 'guarantee_expires_on' => '2029-06-30']), $today);

    expect(frnCodes($expired))->toContain('guarantee_expired')
        ->and(collect($expired)->firstWhere('code', 'guarantee_expired')['severity'])->toBe('error')
        ->and(frnCodes($expiring))->toContain('guarantee_expiring')
        ->and(collect($expiring)->firstWhere('code', 'guarantee_expiring')['params'])->toBe(['days' => 30])
        ->and(frnCodes($unknown))->toBe(['guarantee_expiry_missing'])
        ->and(frnCodes($shortLived))->toBe(['guarantee_ends_before_agreement']);
});

it('refuses an end date that is not after the start date', function () use ($today): void {
    $findings = AgreementRules::evaluate(frnAgreement(['start_date' => '2028-06-01', 'end_date' => '2028-06-01', 'term_months' => 36]), $today);

    expect(frnCodes($findings))->toBe(['end_before_start'])->and($findings[0]['severity'])->toBe('error');
});

it('raises the renewal alert inside the notice window and the expired alert after the end', function () use ($today): void {
    $due = AgreementRules::alerts(frnAgreement(['end_date' => '2028-09-09']), $today);
    $far = AgreementRules::alerts(frnAgreement(['end_date' => '2030-12-31']), $today);
    $expired = AgreementRules::alerts(frnAgreement(['end_date' => '2028-05-31']), $today);

    expect(frnCodes($due))->toBe(['renewal_due'])
        ->and($due[0]['params'])->toBe(['days' => 100])
        ->and($far)->toBe([])
        ->and(frnCodes($expired))->toBe(['agreement_expired']);
});

it('adds the guarantee findings to the alerts of the day', function () use ($today): void {
    $alerts = AgreementRules::alerts(frnAgreement(['guarantee_type' => 'insurance', 'guarantee_expires_on' => '2028-06-20']), $today);

    expect(frnCodes($alerts))->toBe(['guarantee_expiring']);
});

it('detects two exclusivity zones that claim the same postal code', function (): void {
    expect(AgreementRules::zonesOverlap(['postal_codes' => ['20124', '20129']], ['postal_codes' => ['20129', '20100']]))->toBeTrue()
        ->and(AgreementRules::zonesOverlap(['postal_codes' => ['20124']], ['postal_codes' => ['00192']]))->toBeFalse()
        ->and(AgreementRules::zonesOverlap(['postal_codes' => []], ['postal_codes' => ['00192']]))->toBeFalse()
        ->and(AgreementRules::zonesOverlap(null, ['postal_codes' => ['00192']]))->toBeFalse();
});

it('spreads the entry fee in equal monthly shares, the last one absorbing the rounding', function (): void {
    $rows = EntryFeeSchedule::build(10000, 3, CarbonImmutable::parse('2028-01-15'));

    expect(array_column($rows, 'period'))->toBe(['2028-01', '2028-02', '2028-03'])
        ->and(array_column($rows, 'amount'))->toBe(['3333.33', '3333.33', '3333.34'])
        ->and(round(array_sum(array_map('floatval', array_column($rows, 'amount'))), 2))->toBe(10000.0);
});

it('rolls the deferral over the year end and gives no schedule to a free entry', function (): void {
    $rows = EntryFeeSchedule::build(900, 3, CarbonImmutable::parse('2028-11-10'));

    expect(array_column($rows, 'period'))->toBe(['2028-11', '2028-12', '2029-01'])
        ->and(array_column($rows, 'amount'))->toBe(['300.00', '300.00', '300.00'])
        ->and(EntryFeeSchedule::build(0, 12, CarbonImmutable::parse('2028-01-01')))->toBe([])
        ->and(EntryFeeSchedule::build(500, 1, CarbonImmutable::parse('2028-01-01')))->toBe([['period' => '2028-01', 'amount' => '500.00']]);
});
