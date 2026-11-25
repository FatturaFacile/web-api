<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\CorporateConventions\Domain\Enums\BeneficiaryType;
use Modules\CorporateConventions\Domain\Enums\ConventionStatus;
use Modules\CorporateConventions\Domain\Enums\EnrollmentStatus;
use Modules\CorporateConventions\Domain\Enums\ReimbursementStatus;
use Modules\CorporateConventions\Domain\Enums\WelfareCategory;
use Modules\CorporateConventions\Domain\Enums\WelfareJurisdiction;
use Modules\CorporateConventions\Domain\Enums\WelfareProvider;
use Modules\CorporateConventions\Domain\Support\DiscountMatrixValidator;
use Modules\CorporateConventions\Domain\Support\IdentifierHasher;
use Modules\CorporateConventions\Domain\Support\SlugGenerator;
use Modules\CorporateConventions\Domain\Support\WelfareVoucherPolicy;
use Modules\CorporateConventions\Exceptions\ConventionException;
use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;
use Modules\CorporateConventions\Models\WelfareVoucherRedemption;

it('hashes identifiers after trimming, stripping whitespace and upper-casing', function (): void {
    expect(IdentifierHasher::normalize('  ab 12 cd '))->toBe('AB12CD')
        ->and(IdentifierHasher::hash(' ab12cd'))->toBe(hash('sha256', 'AB12CD'))
        ->and(IdentifierHasher::hash('AB12CD'))->toBe(IdentifierHasher::hash('a b 1 2 c d'))
        ->and(IdentifierHasher::hash('AB12CD'))->toHaveLength(64)
        ->and(IdentifierHasher::hint('AB12CD'))->toBe('***2CD')
        ->and(IdentifierHasher::hint('AB'))->toBe('**');
});

it('generates unique slugs and avoids reserved words', function (): void {
    $taken = ['acme-spa'];
    $exists = static fn (string $slug): bool => in_array($slug, $taken, true);

    expect(SlugGenerator::generate('ACME S.p.A.', static fn (): bool => false))->toBe('acme-s-p-a')
        ->and(SlugGenerator::generate('Acme Spa', $exists))->toBe('acme-spa-2')
        ->and(SlugGenerator::generate('Passes', static fn (): bool => false, ['passes']))->toBe('passes-2')
        ->and(SlugGenerator::generate('!!!', static fn (): bool => false))->toBe('convention')
        ->and(SlugGenerator::isValid('acme-spa-2'))->toBeTrue()
        ->and(SlugGenerator::isValid('Acme Spa'))->toBeFalse()
        ->and(SlugGenerator::isValid('-acme'))->toBeFalse();
});

it('rejects an inconsistent discount matrix', function (array $rules, string $code): void {
    try {
        DiscountMatrixValidator::assertValid($rules);
        $this->fail('The matrix should have been refused.');
    } catch (ConventionException $e) {
        expect($e->errorCode())->toBe($code);
    }
})->with([
    'unknown type' => [[['rule_type' => 'NOPE', 'discount_percentage' => 10, 'priority' => 1]], 'discount_rule_type_invalid'],
    'percentage above 100' => [[['rule_type' => 'GLOBAL_PERCENTAGE', 'discount_percentage' => 101, 'priority' => 1]], 'discount_percentage_out_of_range'],
    'zero percentage' => [[['rule_type' => 'GLOBAL_PERCENTAGE', 'discount_percentage' => 0, 'priority' => 1]], 'discount_percentage_required'],
    'category without target' => [[['rule_type' => 'CATEGORY_PERCENTAGE', 'discount_percentage' => 10, 'priority' => 1]], 'discount_rule_target_missing'],
    'price list without target' => [[['rule_type' => 'PRICE_LIST', 'discount_percentage' => 0, 'priority' => 1]], 'discount_rule_target_missing'],
    'duplicated priority' => [[['rule_type' => 'GLOBAL_PERCENTAGE', 'discount_percentage' => 5, 'priority' => 1], ['rule_type' => 'GLOBAL_PERCENTAGE', 'discount_percentage' => 6, 'priority' => 1]], 'discount_priority_duplicated'],
]);

it('accepts a consistent discount matrix', function (): void {
    DiscountMatrixValidator::assertValid([
        ['rule_type' => 'CATEGORY_PERCENTAGE', 'discount_percentage' => 20, 'priority' => 1, 'target_category_id' => 'c'],
        ['rule_type' => 'GLOBAL_PERCENTAGE', 'discount_percentage' => 10, 'priority' => 2],
        ['rule_type' => 'PRICE_LIST', 'discount_percentage' => 0, 'priority' => 3, 'target_price_list_id' => 'p'],
    ]);

    expect(true)->toBeTrue();
});

it('allows Italian Art. 51 vouchers only in EUR and never through Swiss providers', function (): void {
    $policy = new WelfareVoucherPolicy;

    $policy->assertAllowed(WelfareJurisdiction::ItArt51Tuir, WelfareProvider::Edenred, WelfareCategory::Healthcare, 'EUR', '25.00');

    expect(fn () => $policy->assertAllowed(WelfareJurisdiction::ItArt51Tuir, WelfareProvider::Edenred, WelfareCategory::Healthcare, 'CHF', '25.00'))->toThrow(ConventionException::class)
        ->and(fn () => $policy->assertAllowed(WelfareJurisdiction::ItArt51Tuir, WelfareProvider::RekaCard, WelfareCategory::CultureLeisure, 'EUR', '25.00'))->toThrow(ConventionException::class)
        ->and(fn () => $policy->assertAllowed(WelfareJurisdiction::ItArt51Tuir, WelfareProvider::Edenred, WelfareCategory::Healthcare, 'EUR', '0'))->toThrow(ConventionException::class);
});

it('restricts the Swiss providers to their own categories', function (): void {
    $policy = new WelfareVoucherPolicy;

    $policy->assertAllowed(WelfareJurisdiction::ChRekaLunchcheck, WelfareProvider::LunchCheck, WelfareCategory::MealVoucher, 'CHF', '15.00');
    $policy->assertAllowed(WelfareJurisdiction::ChRekaLunchcheck, WelfareProvider::RekaCard, WelfareCategory::SportsFitness, 'CHF', '15.00');

    expect(fn () => $policy->assertAllowed(WelfareJurisdiction::ChRekaLunchcheck, WelfareProvider::LunchCheck, WelfareCategory::SportsFitness, 'CHF', '15.00'))->toThrow(ConventionException::class)
        ->and(fn () => $policy->assertAllowed(WelfareJurisdiction::ChRekaLunchcheck, WelfareProvider::RekaCard, WelfareCategory::MealVoucher, 'CHF', '15.00'))->toThrow(ConventionException::class)
        ->and(fn () => $policy->assertAllowed(WelfareJurisdiction::ChRekaLunchcheck, WelfareProvider::Edenred, WelfareCategory::MealVoucher, 'CHF', '15.00'))->toThrow(ConventionException::class)
        ->and(fn () => $policy->assertAllowed(WelfareJurisdiction::ChRekaLunchcheck, WelfareProvider::RekaCard, WelfareCategory::SportsFitness, 'EUR', '15.00'))->toThrow(ConventionException::class);
});

it('splits a voucher into merchant fee and expected net, and masks its code', function (): void {
    $split = (new WelfareVoucherPolicy)->split('100.00', '3.5');

    expect($split)->toBe(['fee' => '3.50', 'net' => '96.50'])
        ->and(WelfareVoucherPolicy::mask('1234 5678 9012'))->toBe('********9012')
        ->and(WelfareVoucherPolicy::mask('12'))->toBe('**');
});

function ccvRedemption(string $net): WelfareVoucherRedemption
{
    $redemption = new WelfareVoucherRedemption;
    $redemption->net_expected_reimbursement = $net;
    $redemption->reimbursement_status = ReimbursementStatus::PendingSettlement;

    return $redemption;
}

it('reconciles a voucher settled within the tolerance and rejects any other amount', function (): void {
    $now = CarbonImmutable::parse('2026-03-01 10:00:00');

    $ok = ccvRedemption('96.50');
    $ok->submit('BATCH-1');
    $reconciled = $ok->settle('96.51', 'BATCH-1', 'SI-1', '0.01', $now);

    $ko = ccvRedemption('96.50');
    $rejected = $ko->settle('90.00', 'BATCH-2', null, '0.01', $now);

    expect($reconciled)->toBeTrue()
        ->and($ok->reimbursement_status)->toBe(ReimbursementStatus::Reconciled)
        ->and($ok->settled_amount)->toBe('96.51')
        ->and($ok->reconciled_at)->not->toBeNull()
        ->and($rejected)->toBeFalse()
        ->and($ko->reimbursement_status)->toBe(ReimbursementStatus::Rejected)
        ->and($ko->reconciled_at)->toBeNull();
});

it('refuses to settle or re-submit a voucher that is not open', function (): void {
    $redemption = ccvRedemption('10.00');
    $redemption->settle('10.00', 'B', null, '0.01', CarbonImmutable::now());

    expect(fn () => $redemption->settle('10.00', 'B', null, '0.01', CarbonImmutable::now()))->toThrow(ConventionException::class)
        ->and(fn () => $redemption->submit('B'))->toThrow(ConventionException::class);
});

it('tracks the annual consumption of an enrolment and restarts it every year', function (): void {
    $enrollment = new ConventionBeneficiaryEnrollment;
    $enrollment->beneficiary_type = BeneficiaryType::PrimaryEmployee;

    $enrollment->registerUsage('100.00', '20.00', 2026);
    $enrollment->registerUsage('50.00', '10.00', 2026);

    expect($enrollment->annual_used_amount)->toBe('150.00')
        ->and($enrollment->annual_used_subsidy)->toBe('30.00')
        ->and($enrollment->balanceFor(2026)->usedAmount)->toBe('150.00')
        ->and($enrollment->balanceFor(2027)->usedAmount)->toBe('0')
        ->and($enrollment->balanceFor(2026)->subsidyEligible)->toBeTrue();

    $enrollment->registerUsage('10.00', '0', 2027);

    expect($enrollment->annual_used_amount)->toBe('10.00');
});

it('excludes family members from the employer subsidy and handles the enrolment lifecycle', function (): void {
    $family = new ConventionBeneficiaryEnrollment;
    $family->beneficiary_type = BeneficiaryType::FamilyMember;
    $family->status = EnrollmentStatus::Active;
    $now = CarbonImmutable::parse('2026-06-01');

    expect($family->balanceFor(2026)->subsidyEligible)->toBeFalse()
        ->and($family->isUsable($now))->toBeTrue();

    $family->expires_at = $now->subDay();
    expect($family->isUsable($now))->toBeFalse();

    $family->revoke();
    expect($family->status)->toBe(EnrollmentStatus::Revoked)
        ->and(fn () => $family->revoke())->toThrow(ConventionException::class);
});

it('matches partner e-mail domains exactly, never a look-alike suffix', function (): void {
    $convention = new CorporatePartnerConvention;
    $convention->allowed_email_domains = ['@acme.it', 'Partner.CH'];

    expect($convention->acceptsEmail('mario@acme.it'))->toBeTrue()
        ->and($convention->acceptsEmail('anna@PARTNER.ch'))->toBeTrue()
        ->and($convention->acceptsEmail('mario@evil-acme.it'))->toBeFalse()
        ->and($convention->acceptsEmail('mario@acme.it.evil.com'))->toBeFalse()
        ->and($convention->acceptsEmail('no-at-sign'))->toBeFalse();
});

it('matches membership cards by prefix and regular expression', function (): void {
    $convention = new CorporatePartnerConvention;
    $convention->card_number_prefix = 'cral';
    $convention->card_number_regex = '^CRAL\d{6}$';

    expect($convention->matchesCardNumber('CRAL123456'))->toBeTrue()
        ->and($convention->matchesCardNumber('cral123456'))->toBeFalse()
        ->and($convention->matchesCardNumber('XXXX123456'))->toBeFalse()
        ->and($convention->matchesCardNumber(''))->toBeFalse()
        ->and((new CorporatePartnerConvention)->matchesCardNumber('CRAL123456'))->toBeFalse();
});

it('follows the convention lifecycle and refuses illegal transitions', function (): void {
    $convention = new CorporatePartnerConvention;
    $convention->status = ConventionStatus::Draft;

    expect(fn () => $convention->transitionTo(ConventionStatus::Suspended))->toThrow(ConventionException::class)
        ->and(fn () => $convention->transitionTo(ConventionStatus::Active))->toThrow(ConventionException::class);
});

it('checks the family limit, the subsidy configuration and the currency of a convention', function (): void {
    $convention = new CorporatePartnerConvention;
    $convention->allow_family_members = true;
    $convention->max_family_members = 2;

    expect($convention->canAddFamilyMember(1))->toBeTrue()
        ->and($convention->canAddFamilyMember(2))->toBeFalse();

    $convention->valid_from = CarbonImmutable::parse('2026-01-01');
    $convention->valid_to = CarbonImmutable::parse('2025-12-31');
    $convention->country_code = 'IT';
    $convention->currency = 'EUR';

    expect(fn () => $convention->assertConsistent())->toThrow(ConventionException::class);

    $convention->valid_to = null;
    $convention->currency = 'CHF';
    expect(fn () => $convention->assertConsistent())->toThrow(ConventionException::class);

    $convention->currency = 'EUR';
    $convention->assertConsistent();
});
