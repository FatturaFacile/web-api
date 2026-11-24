<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Rebates\Services\RebateTierCalculator;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-15 09:00:00');
    $this->tenant = rbTenant('Distribuzione');
    $this->owner = rbMember($this->tenant, 'owner');
    rbAct($this->owner, $this->tenant);
    $this->customer = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'company_name' => 'Rossi Srl', 'type' => 'customer']);
});

afterEach(fn () => app(TenantContext::class)->clear());

function rbTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function rbMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@rebates-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function rbAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

function rbInvoice(string $net, string $date = '2027-02-10', string $type = 'standard'): SalesInvoice
{
    return SalesInvoice::factory()->create([
        'tenant_id' => test()->tenant->id,
        'contact_id' => test()->customer->id,
        'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
        'invoice_type' => $type,
        'status' => 'posted',
        'date' => $date,
        'total_net' => $net,
        'total_gross' => $net,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rbAgreement(array $overrides = []): array
{
    return test()->postJson('/api/v1/rebates/agreements', [
        'code' => 'RB-'.Str::upper(Str::random(5)),
        'name' => 'Annual volume rebate',
        'direction' => 'sales',
        'contact_id' => test()->customer->id,
        'tier_mode' => 'retroactive',
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-12-31',
        'tiers' => [['threshold_amount' => '10000', 'rate_percent' => '2'], ['threshold_amount' => '50000', 'rate_percent' => '4']],
        ...$overrides,
    ])->assertCreated()->json('data');
}

function rbActive(array $overrides = []): array
{
    $agreement = rbAgreement($overrides);
    test()->postJson("/api/v1/rebates/agreements/{$agreement['id']}/activate")->assertOk();

    return $agreement;
}

it('computes retroactive and incremental rebates on the scale', function (): void {
    $tiers = [['threshold_amount' => '10000.00', 'rate_percent' => '2'], ['threshold_amount' => '50000.00', 'rate_percent' => '4']];
    $calculator = new RebateTierCalculator;

    expect($calculator->rebateOn($tiers, '9999.99', 'retroactive'))->toBe(['rebate' => '0.00', 'rate_percent' => null])
        ->and($calculator->rebateOn($tiers, '20000.00', 'retroactive')['rebate'])->toBe('400.00')
        ->and($calculator->rebateOn($tiers, '60000.00', 'retroactive')['rebate'])->toBe('2400.00')
        ->and($calculator->rebateOn($tiers, '60000.00', 'incremental')['rebate'])->toBe('1200.00')
        ->and($calculator->rebateOn($tiers, '60000.00', 'incremental')['rate_percent'])->toBe('4');
});

it('creates an agreement with tiers and validates the payload', function (): void {
    $agreement = rbAgreement();

    expect($agreement['status'])->toBe('draft')
        ->and($agreement['tiers'])->toHaveCount(2)
        ->and($agreement['contact_name'])->toBe('Rossi Srl');

    $this->postJson('/api/v1/rebates/agreements', ['code' => $agreement['code'], 'name' => 'Duplicate', 'direction' => 'sales', 'contact_id' => $this->customer->id, 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'tiers' => [['threshold_amount' => '1', 'rate_percent' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/rebates/agreements', ['code' => 'X1', 'name' => 'Bad', 'direction' => 'sales', 'contact_id' => $this->customer->id, 'starts_on' => '2027-06-01', 'ends_on' => '2027-01-01', 'tiers' => [['threshold_amount' => '1', 'rate_percent' => '1'], ['threshold_amount' => '1.00', 'rate_percent' => '2']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['ends_on', 'tiers']);
    $this->postJson('/api/v1/rebates/agreements', ['code' => 'X2', 'name' => 'Foreign', 'direction' => 'sales', 'contact_id' => (string) Str::uuid(), 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'tiers' => [['threshold_amount' => '1', 'rate_percent' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors('contact_id');
});

it('locks the structure once the agreement is active', function (): void {
    $agreement = rbAgreement();
    $this->putJson("/api/v1/rebates/agreements/{$agreement['id']}", ['tiers' => [['threshold_amount' => '5000', 'rate_percent' => '1']]])->assertOk()->assertJsonCount(1, 'data.tiers');
    $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/activate")->assertOk()->assertJsonPath('data.status', 'active');
    $this->putJson("/api/v1/rebates/agreements/{$agreement['id']}", ['tiers' => [['threshold_amount' => '1', 'rate_percent' => '1']]])->assertUnprocessable()->assertJsonValidationErrors('agreement');
    $this->putJson("/api/v1/rebates/agreements/{$agreement['id']}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
    $this->deleteJson("/api/v1/rebates/agreements/{$agreement['id']}")->assertUnprocessable();
    $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/activate")->assertUnprocessable();
});

it('accrues only the new part of the rebate from the invoiced volume', function (): void {
    $agreement = rbActive();
    rbInvoice('8000.00');
    rbInvoice('99999.00', '2027-02-11', 'proforma');
    rbInvoice('1000.00', '2026-12-31');

    $first = $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/calculate", ['as_of' => '2027-03-31'])->assertOk()->json('data');
    expect($first['base_amount'])->toBe('8000.00')->and($first['accrual'])->toBeNull()->and($first['delta'])->toBe('0.00');

    rbInvoice('7000.00', '2027-04-10');
    rbInvoice('1000.00', '2027-04-12', 'credit_note');

    $second = $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/calculate", ['as_of' => '2027-06-30'])->assertOk()->json('data');
    expect($second['base_amount'])->toBe('14000.00')->and($second['delta'])->toBe('280.00')->and($second['accrual']['rebate_amount'])->toBe('280.00');

    $again = $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/calculate", ['as_of' => '2027-06-30'])->assertOk()->json('data');
    expect($again['accrual'])->toBeNull()->and($again['previously_accrued'])->toBe('280.00');

    $this->getJson("/api/v1/rebates/agreements/{$agreement['id']}/accruals")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/rebates/agreements?status=active')->assertOk()->assertJsonPath('data.0.open_rebate', '280.00');
    $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/calculate", ['as_of' => '2028-01-01'])->assertUnprocessable()->assertJsonValidationErrors('as_of');
});

it('refuses to calculate a draft agreement', function (): void {
    $agreement = rbAgreement();

    $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/calculate")->assertUnprocessable()->assertJsonValidationErrors('agreement');
});

it('settles a customer rebate as a credit note draft', function (): void {
    $agreement = rbActive();
    rbInvoice('20000.00');
    $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/calculate", ['as_of' => '2027-06-30'])->assertOk();

    $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/settle", [])->assertUnprocessable()->assertJsonValidationErrors('tax_rate');
    $settlement = $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/settle", ['tax_rate' => '22'])->assertCreated()->json('data');

    expect($settlement['amount'])->toBe('400.00')
        ->and($settlement['settlement_type'])->toBe('credit_note_draft')
        ->and($settlement['accruals'])->toHaveCount(1)
        ->and($settlement['accruals'][0]['status'])->toBe('settled');

    $creditNote = SalesInvoice::query()->findOrFail($settlement['credit_note_id']);
    expect($creditNote->invoice_type)->toBe('credit_note')
        ->and($creditNote->status)->toBe('draft')
        ->and((string) $creditNote->contact_id)->toBe((string) $this->customer->id)
        ->and((string) $creditNote->total_net)->toBe('400.00');

    $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/settle", ['tax_rate' => '22'])->assertUnprocessable()->assertJsonValidationErrors('agreement');
    $this->getJson("/api/v1/rebates/settlements?agreement_id={$agreement['id']}")->assertOk()->assertJsonCount(1, 'data');

    // The rebate credit note never lowers the volume it is computed on.
    $creditNote->forceFill(['status' => 'posted'])->saveQuietly();
    $recalculated = $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/calculate", ['as_of' => '2027-06-30'])->assertOk()->json('data');
    expect($recalculated['base_amount'])->toBe('20000.00')->and($recalculated['delta'])->toBe('0.00');
});

it('accrues a supplier rebate on received invoices and settles it as a claim', function (): void {
    $supplier = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'company_name' => 'Fornitore Spa', 'type' => 'supplier']);
    $agreement = rbActive(['direction' => 'purchase', 'contact_id' => $supplier->id, 'tier_mode' => 'incremental']);
    PassiveInvoice::create(['tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'F-1', 'date' => '2027-03-01', 'total_amount' => 73200, 'total_net' => 60000, 'document_type' => 'TD01']);
    PassiveInvoice::create(['tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'F-2', 'date' => '2027-03-05', 'total_amount' => 1220, 'total_net' => 1000, 'document_type' => 'TD04']);

    $result = $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/calculate", ['as_of' => '2027-06-30'])->assertOk()->json('data');
    // 59 000 net: 40 000 at 2% + 9 000 at 4%.
    expect($result['base_amount'])->toBe('59000.00')->and($result['delta'])->toBe('1160.00');

    $settlement = $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/settle")->assertCreated()->json('data');
    expect($settlement['settlement_type'])->toBe('supplier_claim')->and($settlement['credit_note_id'])->toBeNull()->and($settlement['amount'])->toBe('1160.00');
});

it('reports the rebates dashboard', function (): void {
    $agreement = rbActive();
    rbInvoice('20000.00');
    $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/calculate", ['as_of' => '2027-06-30'])->assertOk();
    $this->postJson('/api/v1/rebates/promotions', ['code' => 'SUM', 'name' => 'Summer', 'discount_type' => 'percentage', 'discount_value' => '10', 'starts_on' => '2027-06-01', 'ends_on' => '2027-06-30'])->assertCreated();

    $this->getJson('/api/v1/rebates/dashboard')->assertOk()
        ->assertJsonPath('data.agreements.total', 1)
        ->assertJsonPath('data.accrued_open.sales', '400.00')
        ->assertJsonPath('data.accrued_open.purchase', '0.00')
        ->assertJsonPath('data.promotions.active', 1);
});

it('manages promotions with date ranges and states', function (): void {
    $active = $this->postJson('/api/v1/rebates/promotions', ['code' => 'JUN', 'name' => 'June sale', 'discount_type' => 'percentage', 'discount_value' => '15', 'starts_on' => '2027-06-01', 'ends_on' => '2027-06-30'])->assertCreated()->json('data');
    $this->postJson('/api/v1/rebates/promotions', ['code' => 'JUL', 'name' => 'July sale', 'discount_type' => 'fixed_amount', 'discount_value' => '5', 'min_quantity' => '10', 'starts_on' => '2027-07-01', 'ends_on' => '2027-07-31'])->assertCreated();
    $this->postJson('/api/v1/rebates/promotions', ['code' => 'MAY', 'name' => 'May sale', 'discount_type' => 'percentage', 'discount_value' => '5', 'starts_on' => '2027-05-01', 'ends_on' => '2027-05-31'])->assertCreated();

    expect($active['state'])->toBe('active');
    $this->getJson('/api/v1/rebates/promotions?state=active')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/rebates/promotions?state=scheduled')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'JUL');
    $this->getJson('/api/v1/rebates/promotions?state=expired')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'MAY');
    $this->getJson('/api/v1/rebates/promotions?state=active&on=2027-07-15')->assertOk()->assertJsonPath('data.0.code', 'JUL');

    $this->putJson("/api/v1/rebates/promotions/{$active['id']}", ['is_active' => false])->assertOk()->assertJsonPath('data.state', 'inactive');
    $this->postJson('/api/v1/rebates/promotions', ['code' => 'JUN', 'name' => 'Again', 'discount_type' => 'percentage', 'discount_value' => '15', 'starts_on' => '2027-06-01', 'ends_on' => '2027-06-30'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/rebates/promotions', ['code' => 'BIG', 'name' => 'Too big', 'discount_type' => 'percentage', 'discount_value' => '120', 'starts_on' => '2027-06-01', 'ends_on' => '2027-06-30'])->assertUnprocessable()->assertJsonValidationErrors('discount_value');
    $this->deleteJson("/api/v1/rebates/promotions/{$active['id']}")->assertNoContent();
});

it('keeps the records inside the company and honours the role access', function (): void {
    $agreement = rbAgreement();
    $other = rbTenant('Altra');
    $stranger = rbMember($other, 'owner');
    rbAct($stranger, $other);

    $this->getJson("/api/v1/rebates/agreements/{$agreement['id']}")->assertNotFound();
    $this->getJson('/api/v1/rebates/agreements')->assertOk()->assertJsonCount(0, 'data');

    $viewer = rbMember($this->tenant, 'accountant_readonly');
    rbAct($viewer, $this->tenant);
    $this->getJson('/api/v1/rebates/agreements')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/activate")->assertForbidden();
    $this->postJson('/api/v1/rebates/promotions', ['code' => 'NOPE'])->assertForbidden();
});
