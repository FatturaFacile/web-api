<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Contracts\Jobs\ProcessContractDeadlinesJob;
use Modules\Contracts\Models\ContractAlert;
use Modules\Contracts\Models\ContractRenewal;
use Modules\Contracts\Services\ContractDeadlineService;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierContract;
use Modules\ServiceManagement\Models\ServiceContract;

uses(RefreshDatabase::class);

function contractMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function contractActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

function customerPayload(array $overrides = []): array
{
    return [
        'kind' => 'customer',
        'counterparty_id' => test()->customer->id,
        'contract_number' => 'C-'.Str::random(6),
        'title' => 'Maintenance',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'contract_value' => '1200.00',
        'auto_renew' => true,
        'notice_period_days' => 60,
        'renewal_term_months' => 12,
        ...$overrides,
    ];
}

function supplierPayload(array $overrides = []): array
{
    return [
        'kind' => 'supplier',
        'counterparty_id' => test()->supplier->id,
        'contract_number' => 'S-'.Str::random(6),
        'title' => 'Framework',
        'start_date' => '2026-01-01',
        'end_date' => '2026-06-30',
        'contract_value' => '5000.00',
        'notice_period_days' => 30,
        ...$overrides,
    ];
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->customer = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
    contractActAs(contractMember($this->tenant, 'accountant'), $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('the register stores both kinds in the existing contract tables and computes the notice deadline', function (): void {
    $this->postJson('/api/v1/contract-register', customerPayload())->assertCreated()->assertJsonPath('data.notice_deadline', '2026-11-01');
    $this->postJson('/api/v1/contract-register', supplierPayload())->assertCreated()->assertJsonPath('data.notice_deadline', '2026-05-31');

    expect(ServiceContract::query()->count())->toBe(1)
        ->and(SupplierContract::query()->count())->toBe(1);

    $list = $this->getJson('/api/v1/contract-register')->assertOk()->assertJsonCount(2, 'data')->json('data');

    expect($list[0]['kind'])->toBe('supplier')->and($list[1]['kind'])->toBe('customer');

    $this->getJson('/api/v1/contract-register?kind=customer')->assertOk()->assertJsonCount(1, 'data');
});

test('terms change the notice deadline and manual renewal is recorded', function (): void {
    $id = $this->postJson('/api/v1/contract-register', customerPayload())->json('data.id');

    $this->putJson("/api/v1/contract-register/customer/{$id}/terms", ['notice_period_days' => 90])->assertOk()->assertJsonPath('data.notice_deadline', '2026-10-02');

    $this->postJson("/api/v1/contract-register/customer/{$id}/renew")->assertOk()
        ->assertJsonPath('data.end_date', '2027-12-31')
        ->assertJsonPath('data.notice_deadline', '2027-10-02');

    expect(ContractRenewal::query()->firstOrFail()->mode)->toBe('manual');
});

test('renewing without a term needs a date, and the date wins', function (): void {
    $id = $this->postJson('/api/v1/contract-register', supplierPayload())->json('data.id');

    $this->postJson("/api/v1/contract-register/supplier/{$id}/renew")->assertConflict();
    $this->postJson("/api/v1/contract-register/supplier/{$id}/renew", ['new_end_date' => '2027-03-31'])->assertOk()->assertJsonPath('data.end_date', '2027-03-31');
    $this->postJson("/api/v1/contract-register/supplier/{$id}/renew", ['new_end_date' => '2027-03-31'])->assertConflict();
    $this->postJson("/api/v1/contract-register/supplier/{$id}/renew", ['new_end_date' => '2026-01-01'])->assertConflict();
});

test('alerts come before the notice deadline and the end, once each', function (): void {
    $id = $this->postJson('/api/v1/contract-register', customerPayload(['auto_renew' => false]))->json('data.id');
    $service = app(ContractDeadlineService::class);

    expect($service->run((string) $this->tenant->id, CarbonImmutable::parse('2026-09-01'))['alerts'])->toBe(0)
        ->and($service->run((string) $this->tenant->id, CarbonImmutable::parse('2026-10-05'))['alerts'])->toBe(1)
        ->and($service->run((string) $this->tenant->id, CarbonImmutable::parse('2026-10-06'))['alerts'])->toBe(0)
        ->and($service->run((string) $this->tenant->id, CarbonImmutable::parse('2026-12-10'))['alerts'])->toBe(1);

    $alerts = $this->getJson('/api/v1/contract-register/alerts')->assertOk()->assertJsonCount(2, 'data')->json('data');

    expect(collect($alerts)->pluck('alert_type')->all())->toBe(['notice_deadline', 'expiry'])
        ->and($alerts[0]['contract_id'])->toBe($id);

    $this->postJson("/api/v1/contract-register/alerts/{$alerts[0]['id']}/acknowledge")->assertOk();
    $this->getJson('/api/v1/contract-register/alerts')->assertJsonCount(1, 'data');
});

test('an expired contract renews by itself unless notice was given', function (): void {
    $renews = $this->postJson('/api/v1/contract-register', customerPayload())->json('data.id');
    $stopped = $this->postJson('/api/v1/contract-register', customerPayload())->json('data.id');
    $this->postJson("/api/v1/contract-register/customer/{$stopped}/notice")->assertOk();

    $result = app(ContractDeadlineService::class)->run((string) $this->tenant->id, CarbonImmutable::parse('2027-01-02'));

    expect($result['renewed'])->toBe(1)
        ->and(ServiceContract::query()->findOrFail($renews)->end_date->toDateString())->toBe('2027-12-31')
        ->and(ServiceContract::query()->findOrFail($stopped)->end_date->toDateString())->toBe('2026-12-31')
        ->and(ContractRenewal::query()->firstOrFail()->mode)->toBe('automatic');
});

test('a notice stops the notice alert', function (): void {
    $id = $this->postJson('/api/v1/contract-register', customerPayload(['auto_renew' => false]))->json('data.id');
    $this->postJson("/api/v1/contract-register/customer/{$id}/notice")->assertOk();

    expect(app(ContractDeadlineService::class)->run((string) $this->tenant->id, CarbonImmutable::parse('2026-10-05'))['alerts'])->toBe(0);
});

test('a notice records the day it was given and refuses a future day', function (): void {
    $id = $this->postJson('/api/v1/contract-register', customerPayload(['auto_renew' => false]))->json('data.id');
    $past = now()->subDays(3)->toDateString();

    $this->postJson("/api/v1/contract-register/customer/{$id}/notice", ['notice_given_at' => now()->addDay()->toDateString()])
        ->assertUnprocessable()->assertJsonValidationErrors('notice_given_at');

    $this->postJson("/api/v1/contract-register/customer/{$id}/notice", ['notice_given_at' => $past])->assertOk();

    expect((string) ServiceContract::query()->findOrFail($id)->notice_given_at)->toStartWith($past);
});

test('the daily job works per tenant', function (): void {
    $this->postJson('/api/v1/contract-register', customerPayload(['end_date' => now()->addDays(10)->toDateString(), 'notice_period_days' => null, 'auto_renew' => false]))->assertCreated();

    app(TenantContext::class)->clear();
    (new ProcessContractDeadlinesJob((string) $this->other->id))->handle(app(ContractDeadlineService::class));
    expect(ContractAlert::withoutGlobalScopes()->count())->toBe(0);

    (new ProcessContractDeadlinesJob((string) $this->tenant->id))->handle(app(ContractDeadlineService::class));
    expect(ContractAlert::withoutGlobalScopes()->count())->toBe(1);
});

test('the register is limited to contract roles', function (): void {
    contractActAs(contractMember($this->tenant, 'member'), $this->tenant);

    $this->getJson('/api/v1/contract-register')->assertForbidden();
    $this->postJson('/api/v1/contract-register', customerPayload())->assertForbidden();
    $this->getJson('/api/v1/contract-register/alerts')->assertForbidden();
    $this->postJson('/api/v1/contract-register/alerts/'.Str::uuid().'/acknowledge')->assertForbidden();
    $this->postJson('/api/v1/contract-register/customer/'.Str::uuid().'/notice')->assertForbidden();
});

test('tenants never reach the contracts or counterparties of each other', function (): void {
    $id = $this->postJson('/api/v1/contract-register', customerPayload())->json('data.id');
    app(ContractDeadlineService::class)->run((string) $this->tenant->id, CarbonImmutable::parse('2026-10-05'));

    contractActAs(contractMember($this->other, 'admin'), $this->other);

    $this->getJson('/api/v1/contract-register')->assertOk()->assertJsonPath('data', []);
    $this->getJson('/api/v1/contract-register/alerts')->assertOk()->assertJsonPath('data', []);
    $this->getJson("/api/v1/contract-register/customer/{$id}")->assertNotFound();
    $this->postJson("/api/v1/contract-register/customer/{$id}/renew")->assertNotFound();
    $this->postJson('/api/v1/contract-register', customerPayload())->assertUnprocessable()->assertJsonValidationErrors('counterparty_id');
});

test('the register search matches the contract number and title', function (): void {
    $this->postJson('/api/v1/contract-register', customerPayload(['contract_number' => 'ZZ-100', 'title' => 'Maintenance']))->assertCreated();
    $this->postJson('/api/v1/contract-register', supplierPayload(['contract_number' => 'AA-200', 'title' => 'Cleaning']))->assertCreated();

    expect($this->getJson('/api/v1/contract-register?search=ZZ-1')->assertOk()->json('data.0.contract_number'))->toBe('ZZ-100')
        ->and($this->getJson('/api/v1/contract-register?search=clean')->assertOk()->json('data'))->toHaveCount(1);
});

test('the alert grid exposes the contract number and title as calculated columns', function (): void {
    $zeta = $this->postJson('/api/v1/contract-register', customerPayload(['contract_number' => 'ZZ-100', 'title' => 'Maintenance']))->assertCreated()->json('data.id');
    $alpha = $this->postJson('/api/v1/contract-register', supplierPayload(['contract_number' => 'AA-200', 'title' => 'Cleaning']))->assertCreated()->json('data.id');
    ContractAlert::query()->create(['tenant_id' => $this->tenant->id, 'contract_kind' => 'customer', 'contract_id' => $zeta, 'alert_type' => 'expiry', 'due_on' => '2026-12-31', 'status' => 'open']);
    ContractAlert::query()->create(['tenant_id' => $this->tenant->id, 'contract_kind' => 'supplier', 'contract_id' => $alpha, 'alert_type' => 'notice_deadline', 'due_on' => '2026-11-01', 'status' => 'open']);

    $sorted = $this->getJson('/api/v1/contract-register/alerts?sort_by=contract_number&sort_dir=asc')->assertOk()->json('data');
    expect(collect($sorted)->pluck('contract_number')->all())->toBe(['AA-200', 'ZZ-100'])
        ->and($sorted[0]['contract_title'])->toBe('Cleaning')
        ->and($sorted[0]['is_acknowledged'])->toBeFalse();

    $filtered = $this->getJson('/api/v1/contract-register/alerts?filter[contract_number][]=ZZ-100')->assertOk()->json('data');
    expect($filtered)->toHaveCount(1)->and($filtered[0]['contract_title'])->toBe('Maintenance');

    $values = $this->getJson('/api/v1/contract-register/alerts?grid_values=contract_title')->assertOk()->json('data');
    expect(collect($values)->pluck('value')->all())->toBe(['Cleaning', 'Maintenance']);
});
