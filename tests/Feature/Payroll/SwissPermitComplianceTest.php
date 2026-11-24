<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Payroll\Enums\SwissPermitType;
use Modules\Payroll\Services\SwissPermitComplianceService;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

/**
 * Audit of the Swiss work permits: expiry (expired, expiring inside the window, validity date missing), withholding tax
 * consistency with the permit and the country of residence (permit C and Swiss citizens are taxed in the ordinary way
 * when resident in Switzerland; the other permits, and anyone resident abroad, are subject to the source tax), and the
 * permit G with a Swiss residence. The rules are those coded in SwissPermitType; the sources named in the class docblock
 * (FTA circular 45) were not re-read: these tests pin the behaviour, they do not certify the rules.
 */
beforeEach(function (): void {
    $this->tenant = payrollTenant();
    app(TenantContext::class)->setTenant($this->tenant);
    $this->asOf = CarbonImmutable::parse('2026-06-15');
});

/**
 * @param  array<string, mixed>  $profile
 * @param  array<string, mixed>  $employee
 */
function permitProfile(array $profile, array $employee = []): void
{
    $worker = payrollEmployee(test()->tenant, [...$employee, 'first_name' => 'Anna', 'last_name' => 'Verdi', 'full_name' => 'Anna Verdi '.Str::random(3)]);
    payrollProfile(test()->tenant, $worker, [
        'swiss_permit_type' => null,
        'residence_permit' => null,
        'swiss_permit_valid_until' => null,
        'swiss_permit_number' => 'P-'.Str::random(5),
        'residence_country' => 'CH',
        'is_withholding_tax_subject' => false,
        'withholding_tax_code' => null,
        ...$profile,
    ]);
}

/**
 * @return array<string, mixed>
 */
function permitAudit(?int $window = null): array
{
    $service = app(SwissPermitComplianceService::class);

    return $window === null ? $service->audit(test()->asOf) : $service->audit(test()->asOf, $window);
}

/**
 * @param  array<string, mixed>  $audit
 * @return list<string>
 */
function permitAlertTypes(array $audit): array
{
    return array_column($audit['alerts'], 'type');
}

test('an expired permit is an error with the negative days remaining', function (): void {
    permitProfile(['swiss_permit_type' => 'B', 'swiss_permit_valid_until' => '2026-06-10', 'is_withholding_tax_subject' => true, 'withholding_tax_code' => 'A0N']);

    $audit = permitAudit();

    expect($audit['as_of'])->toBe('2026-06-15')
        ->and($audit['summary'])->toBe(['total_profiles_checked' => 1, 'expired_count' => 1, 'expiring_soon_count' => 0, 'qst_mismatch_count' => 0, 'is_compliant' => false])
        ->and($audit['alerts'][0]['type'])->toBe('permit_expired')
        ->and($audit['alerts'][0]['severity'])->toBe('error')
        ->and($audit['alerts'][0]['days_remaining'])->toBe(-5)
        ->and($audit['alerts'][0]['valid_until'])->toBe('2026-06-10');
});

test('the warning window includes its last day and excludes the next', function (): void {
    permitProfile(['swiss_permit_type' => 'B', 'swiss_permit_valid_until' => '2026-08-14', 'is_withholding_tax_subject' => true, 'withholding_tax_code' => 'A0N']);
    permitProfile(['swiss_permit_type' => 'B', 'swiss_permit_valid_until' => '2026-08-15', 'is_withholding_tax_subject' => true, 'withholding_tax_code' => 'A0N']);
    permitProfile(['swiss_permit_type' => 'B', 'swiss_permit_valid_until' => '2026-06-15', 'is_withholding_tax_subject' => true, 'withholding_tax_code' => 'A0N']);

    // 2026-06-15 + 60 days = 2026-08-14.
    $audit = permitAudit();
    $byDate = collect($audit['alerts'])->keyBy('valid_until');

    expect($byDate->keys()->sort()->values()->all())->toBe(['2026-06-15', '2026-08-14'])
        ->and($byDate['2026-08-14']['type'])->toBe('permit_expiring')
        ->and($byDate['2026-08-14']['days_remaining'])->toBe(60)
        ->and($byDate['2026-06-15']['days_remaining'])->toBe(0)
        ->and($byDate['2026-06-15']['severity'])->toBe('warning')
        ->and($audit['summary']['expiring_soon_count'])->toBe(2)
        ->and($audit['summary']['expired_count'])->toBe(0);

    expect(permitAudit(10)['summary']['expiring_soon_count'])->toBe(1);
});

test('a permit that needs a validity date and has none is reported, permit C does not need one', function (): void {
    permitProfile(['swiss_permit_type' => 'L', 'is_withholding_tax_subject' => true, 'withholding_tax_code' => 'A0N']);
    permitProfile(['swiss_permit_type' => 'C']);

    $audit = permitAudit();

    expect(permitAlertTypes($audit))->toBe(['permit_validity_missing'])
        ->and($audit['alerts'][0]['valid_until'])->toBeNull()
        ->and($audit['alerts'][0]['days_remaining'])->toBeNull()
        ->and($audit['summary']['total_profiles_checked'])->toBe(2);
});

test('a permit subject to the source tax needs the subject flag and a tariff code', function (): void {
    permitProfile(['swiss_permit_type' => 'B', 'swiss_permit_valid_until' => '2027-12-31']);
    permitProfile(['swiss_permit_type' => 'B', 'swiss_permit_valid_until' => '2027-12-31', 'is_withholding_tax_subject' => true, 'withholding_tax_code' => '  ']);
    permitProfile(['swiss_permit_type' => 'B', 'swiss_permit_valid_until' => '2027-12-31', 'is_withholding_tax_subject' => true, 'withholding_tax_code' => 'A0N']);

    $audit = permitAudit();

    expect(permitAlertTypes($audit))->toBe(['qst_mismatch', 'qst_mismatch'])
        ->and($audit['summary']['qst_mismatch_count'])->toBe(2)
        ->and($audit['alerts'][0]['message'])->toContain('richiede');
});

test('permit C and Swiss citizens resident in Switzerland are taxed in the ordinary way', function (): void {
    permitProfile(['swiss_permit_type' => 'C', 'is_withholding_tax_subject' => true, 'withholding_tax_code' => 'A0N']);
    permitProfile(['swiss_permit_type' => 'CH', 'is_withholding_tax_subject' => true, 'withholding_tax_code' => 'A0N']);
    permitProfile(['swiss_permit_type' => 'C', 'is_withholding_tax_subject' => false]);

    $audit = permitAudit();

    expect(permitAlertTypes($audit))->toBe(['qst_mismatch', 'qst_mismatch'])
        ->and($audit['alerts'][0]['message'])->toContain('tassazione ordinaria')
        ->and($audit['summary']['qst_mismatch_count'])->toBe(2);
});

test('whoever lives abroad is subject to the source tax whatever the permit', function (): void {
    permitProfile(['swiss_permit_type' => 'C', 'residence_country' => 'IT']);
    permitProfile(['swiss_permit_type' => 'C', 'residence_country' => 'it', 'is_withholding_tax_subject' => true, 'withholding_tax_code' => 'A0N']);

    $audit = permitAudit();

    expect(permitAlertTypes($audit))->toBe(['qst_mismatch'])
        ->and($audit['alerts'][0]['message'])->toContain('richiede');
});

test('a frontier permit with a Swiss residence is flagged, with a residence abroad it is consistent', function (): void {
    permitProfile(['swiss_permit_type' => 'G', 'swiss_permit_valid_until' => '2027-12-31', 'residence_country' => 'CH', 'is_withholding_tax_subject' => true, 'withholding_tax_code' => 'A0N']);
    permitProfile(['swiss_permit_type' => 'G', 'swiss_permit_valid_until' => '2027-12-31', 'residence_country' => 'IT', 'is_withholding_tax_subject' => true, 'withholding_tax_code' => 'A0N']);

    $audit = permitAudit();

    expect(permitAlertTypes($audit))->toBe(['permit_residence_mismatch'])
        ->and($audit['alerts'][0]['severity'])->toBe('warning')
        ->and($audit['summary']['is_compliant'])->toBeTrue();
});

test('terminated employees and profiles without a permit are left out, an unknown permit code is still checked for expiry', function (): void {
    permitProfile(['swiss_permit_type' => 'B', 'swiss_permit_valid_until' => '2026-01-01'], ['termination_date' => '2026-03-31']);
    permitProfile([]);
    permitProfile(['swiss_permit_type' => null, 'residence_permit' => 'XY', 'swiss_permit_valid_until' => '2026-05-01']);

    $audit = permitAudit();

    expect($audit['summary']['total_profiles_checked'])->toBe(3)
        ->and($audit['alerts'])->toHaveCount(1)
        ->and($audit['alerts'][0]['type'])->toBe('permit_expired')
        ->and($audit['alerts'][0]['permit_type'])->toBe('XY');
});

test('the audit only sees the profiles of the active company', function (): void {
    permitProfile(['swiss_permit_type' => 'B', 'swiss_permit_valid_until' => '2026-01-01', 'is_withholding_tax_subject' => true, 'withholding_tax_code' => 'A0N']);

    $other = payrollTenant();
    app(TenantContext::class)->setTenant($other);

    expect(permitAudit()['summary']['total_profiles_checked'])->toBe(0)
        ->and(permitAudit()['summary']['is_compliant'])->toBeTrue();
});

test('the permit enum matches codes without regard to case and knows its rules', function (): void {
    $type = SwissPermitType::class;

    expect($type::tryFromInsensitive(' g '))->toBe($type::G)
        ->and($type::tryFromInsensitive('ci'))->toBe($type::Ci)
        ->and($type::tryFromInsensitive('meldeverfahren'))->toBe($type::Meldeverfahren)
        ->and($type::tryFromInsensitive(''))->toBeNull()
        ->and($type::tryFromInsensitive(null))->toBeNull()
        ->and($type::tryFromInsensitive('Z'))->toBeNull()
        ->and($type::C->requiresWithholdingTax('CH'))->toBeFalse()
        ->and($type::C->requiresWithholdingTax('DE'))->toBeTrue()
        ->and($type::B->requiresValidityDate())->toBeTrue()
        ->and($type::SwissCitizen->requiresValidityDate())->toBeFalse()
        ->and($type::G->label())->toContain('Frontaliere');
});
