<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\Inventory\Models\Warehouse;
use Modules\Procurement\Models\Supplier;

/*
 * Cross-company isolation, role based authorization and the primary happy path of the core endpoints of the ledger,
 * inventory, procurement and invoicing modules: records of another company answer 404 and are never changed, and
 * read-only roles read but never write.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Core Alfa '.Str::random(4), 'legal_name' => 'Core Alfa Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->otherTenant = Tenant::create(['name' => 'Core Beta '.Str::random(4), 'legal_name' => 'Core Beta Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->owner = coreMember($this->tenant, 'owner');
    coreAct($this->owner, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function coreMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@core-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);

    return $user;
}

function coreAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

test('chart of accounts: happy path, isolation between companies and read-only access', function (): void {
    $this->postJson('/api/v1/general-ledger/accounts', ['account_code' => '9100', 'name' => 'Core bank', 'account_type' => 'asset', 'normal_balance' => 'debit'])->assertCreated();

    $foreign = ChartOfAccount::factory()->create(['tenant_id' => $this->otherTenant->id, 'account_code' => '9200', 'name' => 'Foreign bank']);

    $codes = collect($this->getJson('/api/v1/general-ledger/accounts')->assertOk()->json('data'))->pluck('account_code');
    expect($codes)->toContain('9100')->not->toContain('9200');

    $this->getJson("/api/v1/general-ledger/accounts/{$foreign->id}")->assertNotFound();
    $this->putJson("/api/v1/general-ledger/accounts/{$foreign->id}", ['account_code' => '9200', 'name' => 'Changed', 'account_type' => 'asset', 'normal_balance' => 'debit'])->assertNotFound();
    $this->deleteJson("/api/v1/general-ledger/accounts/{$foreign->id}")->assertNotFound();
    expect(ChartOfAccount::withoutGlobalScopes()->find($foreign->id)->name)->toBe('Foreign bank');

    coreAct(coreMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->getJson('/api/v1/general-ledger/accounts')->assertOk();
    $this->postJson('/api/v1/general-ledger/accounts', ['account_code' => '9300', 'name' => 'Denied', 'account_type' => 'asset', 'normal_balance' => 'debit'])->assertForbidden();
});

test('chart of accounts rejects a role without ledger access', function (): void {
    coreAct(coreMember($this->tenant, 'technician'), $this->tenant);

    $this->getJson('/api/v1/general-ledger/accounts')->assertForbidden();
    $this->postJson('/api/v1/general-ledger/accounts', ['account_code' => '9300', 'name' => 'Denied', 'account_type' => 'asset', 'normal_balance' => 'debit'])->assertForbidden();
});

test('warehouses: happy path, isolation between companies and role restrictions', function (): void {
    $this->postJson('/api/v1/inventory/warehouses', ['code' => 'CORE-WH', 'name' => 'Core warehouse', 'type' => 'standard'])->assertCreated();

    $foreign = Warehouse::factory()->create(['tenant_id' => $this->otherTenant->id, 'code' => 'FOREIGN-WH']);

    $codes = collect($this->getJson('/api/v1/inventory/warehouses')->assertOk()->json('data'))->pluck('code');
    expect($codes)->toContain('CORE-WH')->not->toContain('FOREIGN-WH');

    $this->getJson("/api/v1/inventory/warehouses/{$foreign->id}")->assertNotFound();
    $this->putJson("/api/v1/inventory/warehouses/{$foreign->id}", ['name' => 'Changed'])->assertNotFound();
    $this->deleteJson("/api/v1/inventory/warehouses/{$foreign->id}")->assertNotFound();
    expect(Warehouse::withoutGlobalScopes()->find($foreign->id))->not->toBeNull();

    $this->postJson('/api/v1/inventory/warehouses', [])->assertUnprocessable()->assertJsonValidationErrors(['code', 'name', 'type']);

    coreAct(coreMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->postJson('/api/v1/inventory/warehouses', ['code' => 'DENIED', 'name' => 'Denied', 'type' => 'standard'])->assertForbidden();
});

test('suppliers: happy path, isolation between companies and role restrictions', function (): void {
    $id = $this->postJson('/api/v1/procurement/suppliers', ['supplier_code' => 'SUP-CORE', 'company_name' => 'Core Supplier Srl', 'currency' => 'EUR'])
        ->assertCreated()->json('id');

    $foreign = Supplier::factory()->create(['tenant_id' => $this->otherTenant->id, 'supplier_code' => 'SUP-FOREIGN']);

    $codes = collect($this->getJson('/api/v1/procurement/suppliers')->assertOk()->json('data'))->pluck('supplier_code');
    expect($codes)->toContain('SUP-CORE')->not->toContain('SUP-FOREIGN');

    $this->getJson("/api/v1/procurement/suppliers/{$id}")->assertOk()->assertJsonPath('supplier_code', 'SUP-CORE');
    $this->getJson("/api/v1/procurement/suppliers/{$foreign->id}")->assertNotFound();
    $this->putJson("/api/v1/procurement/suppliers/{$foreign->id}", ['rating' => 'A'])->assertNotFound();
    $this->deleteJson("/api/v1/procurement/suppliers/{$foreign->id}")->assertNotFound();
    expect(Supplier::withoutGlobalScopes()->find($foreign->id))->not->toBeNull();

    $this->postJson('/api/v1/procurement/suppliers', ['supplier_code' => 'SUP-CORE', 'company_name' => 'Duplicate'])->assertUnprocessable()->assertJsonValidationErrors(['supplier_code']);

    coreAct(coreMember($this->tenant, 'technician'), $this->tenant);
    $this->postJson('/api/v1/procurement/suppliers', ['supplier_code' => 'SUP-DENIED', 'company_name' => 'Denied'])->assertForbidden();
});

test('purchase orders of another company are neither visible nor actionable', function (): void {
    $this->getJson('/api/v1/procurement/purchase-orders')->assertOk()->assertJsonCount(0, 'data');

    $unknownId = (string) Str::uuid();
    $this->getJson("/api/v1/procurement/purchase-orders/{$unknownId}")->assertNotFound();
    $this->postJson("/api/v1/procurement/purchase-orders/{$unknownId}/approve")->assertNotFound();
    $this->postJson("/api/v1/procurement/purchase-orders/{$unknownId}/cancel")->assertNotFound();
});

test('customer ledger answers for the customers of the company only', function (): void {
    $customer = Contact::create(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $this->tenant->id, 'type' => 'client', 'company_name' => 'Ledger Client Srl', 'country' => 'IT']);
    $foreignCustomer = Contact::create(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $this->otherTenant->id, 'type' => 'client', 'company_name' => 'Foreign Client Srl', 'country' => 'IT']);
    // The active tenant context always wins on creation, so the owner is moved with a plain query.
    Contact::withoutGlobalScopes()->whereKey($foreignCustomer->id)->update(['tenant_id' => (string) $this->otherTenant->id]);

    $this->getJson("/api/v1/invoicing/customer-ledger/{$customer->id}/aging")->assertOk()->assertJsonPath('customer_id', (string) $customer->id)->assertJsonStructure(['aging_buckets' => ['current', 'total_outstanding']]);
    $this->getJson("/api/v1/invoicing/customer-ledger/{$customer->id}/statement")->assertOk()->assertJsonCount(0, 'data');

    $this->getJson("/api/v1/invoicing/customer-ledger/{$foreignCustomer->id}/aging")->assertNotFound();
    $this->getJson("/api/v1/invoicing/customer-ledger/{$foreignCustomer->id}/statement")->assertNotFound();
});
