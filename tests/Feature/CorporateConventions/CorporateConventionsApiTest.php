<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/CorporateConventionsSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ccvSetUp($this);
});

it('creates a draft convention with its discount matrix and a public slug', function (): void {
    $response = $this->postJson('/api/v1/sales/corporate-conventions', ccvPayload(['name' => 'Convenzione Acme Spa']))->assertCreated();

    expect($response->json('data.status'))->toBe('DRAFT')
        ->and($response->json('data.slug'))->toBe('convenzione-acme-spa')
        ->and($response->json('data.landing_path'))->toBe('/convenzioni/convenzione-acme-spa')
        ->and($response->json('data.discount_rules'))->toHaveCount(1);
});

it('refuses a duplicated code, a currency that does not fit the country and a bad VAT number', function (): void {
    $payload = ccvPayload(['code' => 'DUP-1']);
    $this->postJson('/api/v1/sales/corporate-conventions', $payload)->assertCreated();

    $this->postJson('/api/v1/sales/corporate-conventions', ccvPayload(['code' => 'DUP-1']))->assertStatus(422)->assertJsonValidationErrors(['code']);
    $this->postJson('/api/v1/sales/corporate-conventions', ccvPayload(['country_code' => 'CH', 'currency' => 'EUR']))->assertStatus(422);
    $this->postJson('/api/v1/sales/corporate-conventions', ccvPayload(['partner_vat_number' => '123']))->assertStatus(422)->assertJsonValidationErrors(['partner_vat_number']);
});

it('refuses to activate a convention without a discount matrix or without verification data', function (): void {
    $noRules = $this->postJson('/api/v1/sales/corporate-conventions', ccvPayload(['discount_rules' => []]))->assertCreated()->json('data');
    $noDomains = $this->postJson('/api/v1/sales/corporate-conventions', ccvPayload(['verification_methods' => ['WORK_EMAIL_OTP'], 'allowed_email_domains' => []]))->assertCreated()->json('data');

    $this->postJson('/api/v1/sales/corporate-conventions/'.$noRules['id'].'/transition', ['status' => 'ACTIVE'])->assertStatus(422);
    $this->postJson('/api/v1/sales/corporate-conventions/'.$noDomains['id'].'/transition', ['status' => 'ACTIVE'])->assertStatus(422);
});

it('refuses two rules with the same priority', function (): void {
    $this->postJson('/api/v1/sales/corporate-conventions', ccvPayload(['discount_rules' => [
        ['priority' => 1, 'rule_type' => 'GLOBAL_PERCENTAGE', 'discount_percentage' => 5],
        ['priority' => 1, 'rule_type' => 'GLOBAL_PERCENTAGE', 'discount_percentage' => 8],
    ]]))->assertStatus(422);
});

it('follows the lifecycle draft, active, suspended, expired and refuses going back', function (): void {
    $convention = ccvActiveConvention($this);
    $url = '/api/v1/sales/corporate-conventions/'.$convention['id'].'/transition';

    $this->postJson($url, ['status' => 'SUSPENDED'])->assertOk()->assertJsonPath('data.status', 'SUSPENDED');
    $this->postJson($url, ['status' => 'ACTIVE'])->assertOk();
    $this->postJson($url, ['status' => 'EXPIRED'])->assertOk();
    $this->postJson($url, ['status' => 'ACTIVE'])->assertStatus(409);
});

it('imports the employee list as SHA-256 hashes, skipping duplicates, and reports its use', function (): void {
    $convention = $this->postJson('/api/v1/sales/corporate-conventions', ccvPayload())->assertCreated()->json('data');
    $url = '/api/v1/sales/corporate-conventions/'.$convention['id'].'/identifiers';

    $this->postJson($url, ['identifiers' => ['mat 1', 'MAT1', 'MAT-2'], 'dry_run' => true])->assertSuccessful();
    expect(DB::table('sales_convention_allowed_hashes')->count())->toBe(0);

    $this->postJson($url, ['identifiers' => ['mat 1', 'MAT1', 'MAT-2']])->assertSuccessful();

    $stored = DB::table('sales_convention_allowed_hashes')->pluck('identifier_sha256')->all();
    expect($stored)->toHaveCount(2)
        ->and($stored)->toContain(hash('sha256', 'MAT1'))
        ->and($stored)->not->toContain('MAT1');

    $this->getJson('/api/v1/sales/corporate-conventions/'.$convention['id'].'/identifiers/stats')->assertOk();
});

it('enrols an employee from the hashed list once and issues a pass with a QR payload', function (): void {
    $convention = ccvActiveConvention($this);

    $first = $this->postJson('/api/v1/sales/corporate-conventions/enrollments/verify', ['identifier' => ' mat-001 ', 'convention_id' => $convention['id'], 'full_name' => 'Mario Rossi'])->assertCreated();

    expect($first->json('data.status'))->toBe('ACTIVE')
        ->and($first->json('data.beneficiary_type'))->toBe('PRIMARY_EMPLOYEE')
        ->and($first->json('data.qr_payload'))->toStartWith('FFCONV:')
        ->and(Str::isUuid($first->json('data.wallet_pass_id')))->toBeTrue();

    $this->postJson('/api/v1/sales/corporate-conventions/enrollments/verify', ['identifier' => 'MAT-001', 'convention_id' => $convention['id'], 'full_name' => 'Altro Utente'])
        ->assertStatus(409);
    $this->postJson('/api/v1/sales/corporate-conventions/enrollments/verify', ['identifier' => 'NOT-LISTED', 'convention_id' => $convention['id'], 'full_name' => 'Ignoto'])
        ->assertStatus(422);
});

it('recognises an enrolled pass from its QR payload', function (): void {
    $convention = ccvActiveConvention($this);
    $enrollment = ccvEnroll($this, $convention);

    $this->postJson('/api/v1/sales/corporate-conventions/enrollments/verify', ['identifier' => $enrollment['qr_payload']])
        ->assertOk()
        ->assertJsonPath('data.id', $enrollment['id']);
});

it('limits family members to the configured maximum and to one spouse', function (): void {
    $convention = ccvActiveConvention($this, ['max_family_members' => 2]);
    $employee = ccvEnroll($this, $convention);
    $url = '/api/v1/sales/corporate-conventions/enrollments/'.$employee['id'].'/family-members';

    $this->postJson($url, ['full_name' => 'Anna Rossi', 'relationship' => 'SPOUSE'])->assertCreated();
    $this->postJson($url, ['full_name' => 'Bianca Rossi', 'relationship' => 'SPOUSE'])->assertStatus(409);
    $this->postJson($url, ['full_name' => 'Luca Rossi', 'relationship' => 'CHILD'])->assertCreated();
    $this->postJson($url, ['full_name' => 'Sara Rossi', 'relationship' => 'CHILD'])->assertStatus(409);
    $this->postJson($url, ['full_name' => 'Cugino Rossi', 'relationship' => 'COUSIN'])->assertStatus(422);
});

it('prices a cart with the convention discount and the employer subsidy', function (): void {
    $convention = ccvActiveConvention($this);
    ccvEnroll($this, $convention);

    $response = $this->postJson('/api/v1/sales/corporate-conventions/pos/pricing', [
        'identifier' => 'MAT-001',
        'convention_id' => $convention['id'],
        'lines' => [['quantity' => 1, 'unit_price' => 100], ['quantity' => 2, 'unit_price' => 50]],
    ])->assertOk();

    expect($response->json('data.totals.total_gross_amount'))->toBe('200.00')
        ->and($response->json('data.totals.convention_discount_amount'))->toBe('20.00')
        ->and($response->json('data.totals.payable_after_discount'))->toBe('180.00')
        ->and($response->json('data.totals.employer_subsidy_amount'))->toBe('90.00')
        ->and($response->json('data.totals.employee_paid_amount'))->toBe('90.00');
});

it('refuses to price for a revoked pass', function (): void {
    $convention = ccvActiveConvention($this);
    $employee = ccvEnroll($this, $convention);

    $this->postJson('/api/v1/sales/corporate-conventions/enrollments/'.$employee['id'].'/revoke')->assertOk();

    $this->postJson('/api/v1/sales/corporate-conventions/pos/pricing', ['identifier' => 'MAT-001', 'convention_id' => $convention['id'], 'lines' => [['quantity' => 1, 'unit_price' => 10]]])
        ->assertStatus(409);
});

it('records the split billing at checkout, consumes the annual cap and refuses a second checkout of the same document', function (): void {
    $convention = ccvActiveConvention($this, ['annual_spending_cap_per_beneficiary' => 150]);
    $employee = ccvEnroll($this, $convention);
    $document = (string) Str::uuid();
    $checkout = [
        'enrollment_id' => $employee['id'],
        'source_document_type' => 'POS_RECEIPT',
        'source_document_id' => $document,
        'lines' => [['quantity' => 1, 'unit_price' => 100]],
    ];

    $response = $this->postJson('/api/v1/sales/corporate-conventions/pos/checkout', $checkout)->assertCreated();

    expect($response->json('data.split_billing.billing_status'))->toBe('PENDING_CONSOLIDATION')
        ->and($response->json('data.split_billing.convention_discount_amount'))->toBe('10.00')
        ->and($response->json('data.split_billing.employer_subsidy_amount'))->toBe('45.00')
        ->and($response->json('data.split_billing.employee_paid_amount'))->toBe('45.00');

    $this->getJson('/api/v1/sales/corporate-conventions/enrollments/'.$employee['id'])
        ->assertOk()
        ->assertJsonPath('data.annual_used_amount', '90.00')
        ->assertJsonPath('data.annual_used_subsidy', '45.00')
        ->assertJsonPath('data.annual_cap_remaining', '60.00');

    $this->postJson('/api/v1/sales/corporate-conventions/pos/checkout', $checkout)->assertStatus(409);
    expect(DB::table('sales_convention_split_billings')->count())->toBe(1);
});

it('keeps conventions, enrolments and split billings private to their company', function (): void {
    $convention = ccvActiveConvention($this);
    $employee = ccvEnroll($this, $convention);

    treasuryActor($this, 'accountant', $this->otherTenant);

    $this->getJson('/api/v1/sales/corporate-conventions/'.$convention['id'])->assertNotFound();
    $this->getJson('/api/v1/sales/corporate-conventions/enrollments/'.$employee['id'])->assertNotFound();
    $this->getJson('/api/v1/sales/corporate-conventions')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/sales/corporate-conventions/pos/pricing', ['identifier' => 'MAT-001', 'lines' => [['quantity' => 1, 'unit_price' => 10]]])->assertStatus(404);
});

it('allows the same code in two companies but keeps the public slug globally unique', function (): void {
    $this->postJson('/api/v1/sales/corporate-conventions', ccvPayload(['code' => 'SAME', 'name' => 'Welfare Comune']))->assertCreated()->assertJsonPath('data.slug', 'welfare-comune');

    treasuryActor($this, 'accountant', $this->otherTenant);

    $this->postJson('/api/v1/sales/corporate-conventions', ccvPayload(['code' => 'SAME', 'name' => 'Welfare Comune']))->assertCreated()->assertJsonPath('data.slug', 'welfare-comune-2');
});

it('denies the module to a user without a sales or finance role', function (): void {
    treasuryActor($this, 'viewer');

    $this->getJson('/api/v1/sales/corporate-conventions')->assertForbidden();
});

it('returns the ROI analytics of the active conventions', function (): void {
    $convention = ccvActiveConvention($this, ['eligible_population' => 10]);
    $employee = ccvEnroll($this, $convention);
    $this->postJson('/api/v1/sales/corporate-conventions/pos/checkout', [
        'enrollment_id' => $employee['id'],
        'source_document_type' => 'POS_RECEIPT',
        'source_document_id' => (string) Str::uuid(),
        'lines' => [['quantity' => 1, 'unit_price' => 100, 'unit_cost' => 40]],
    ])->assertCreated();

    $this->getJson('/api/v1/sales/corporate-conventions/analytics/roi')->assertOk();
});
