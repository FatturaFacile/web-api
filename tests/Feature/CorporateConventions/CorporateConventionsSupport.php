<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Str;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

/**
 * A company with an open fiscal calendar and an accountant (finance writer) signed in, plus a second company for isolation checks.
 */
function ccvSetUp(object $test, string $country = 'IT'): void
{
    $suffix = Str::upper(Str::random(5));
    $test->tenant = Tenant::create(['name' => 'Retail '.$suffix, 'legal_name' => 'Retail '.$suffix.' Srl', 'country' => $country, 'default_currency' => $country === 'CH' ? 'CHF' : 'EUR']);
    $test->otherTenant = Tenant::create(['name' => 'Altra '.$suffix, 'legal_name' => 'Altra '.$suffix.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);

    foreach ([$test->tenant, $test->otherTenant] as $tenant) {
        fiscalCalendar($tenant, (int) now()->year);
    }

    $test->actor = treasuryActor($test, 'accountant');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ccvPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'CONV-'.Str::upper(Str::random(6)),
        'name' => 'Convenzione Acme',
        'partner_company_name' => 'Acme S.p.A.',
        'partner_type' => 'CORPORATE',
        'country_code' => 'IT',
        'currency' => 'EUR',
        'valid_from' => now()->startOfYear()->toDateString(),
        'verification_methods' => ['HASHED_EMPLOYEE_LIST', 'WORK_EMAIL_OTP'],
        'allowed_email_domains' => ['@acme.it'],
        'allow_family_members' => true,
        'max_family_members' => 2,
        'employer_subsidy_enabled' => true,
        'employer_subsidy_type' => 'PERCENTAGE',
        'employer_subsidy_value' => 50,
        'discount_rules' => [['priority' => 1, 'rule_type' => 'GLOBAL_PERCENTAGE', 'discount_percentage' => 10]],
    ], $overrides);
}

/**
 * Creates a convention through the API, loads its hashed employee list and activates it.
 *
 * @param  array<string, mixed>  $overrides
 * @param  list<string>  $identifiers
 * @return array<string, mixed> the data of the active convention
 */
function ccvActiveConvention(object $test, array $overrides = [], array $identifiers = ['MAT-001', 'MAT-002']): array
{
    $convention = $test->postJson('/api/v1/sales/corporate-conventions', ccvPayload($overrides))->assertCreated()->json('data');

    if ($identifiers !== []) {
        $test->postJson('/api/v1/sales/corporate-conventions/'.$convention['id'].'/identifiers', ['identifiers' => $identifiers])->assertSuccessful();
    }

    $test->postJson('/api/v1/sales/corporate-conventions/'.$convention['id'].'/transition', ['status' => 'ACTIVE'])->assertOk();

    return $convention;
}

/**
 * @return array<string, mixed> the data of the enrolment
 */
function ccvEnroll(object $test, array $convention, string $identifier = 'MAT-001', string $name = 'Mario Rossi'): array
{
    return $test->postJson('/api/v1/sales/corporate-conventions/enrollments/verify', [
        'identifier' => $identifier,
        'convention_id' => $convention['id'],
        'full_name' => $name,
    ])->assertSuccessful()->json('data');
}
