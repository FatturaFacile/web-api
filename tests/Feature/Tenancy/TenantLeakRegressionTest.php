<?php

declare(strict_types=1);

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceQrBillData;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Regression tests of the defects found by the tenant isolation verification (registry driven tests of this folder).
 */
beforeEach(function (): void {
    $this->tenantA = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->tenantB = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA', 'country' => 'CH', 'default_currency' => 'CHF']);
});

/**
 * @return array{0: Tenant, 1: User}
 */
function leakOwner(Tenant $tenant): array
{
    $user = User::create([
        'name' => 'Owner '.Str::random(4),
        'email' => Str::random(12).'@example.test',
        'password' => bcrypt(Str::password(24)),
        'locale' => 'it',
    ]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);

    return [$tenant, $user];
}

function leakInvoice(Tenant $tenant, string $number, string $scorReference): Invoice
{
    app(TenantContext::class)->setTenant($tenant);
    $contact = Contact::create(['tenant_id' => $tenant->id, 'company_name' => 'Cliente '.$number, 'country' => 'CH']);
    $invoice = Invoice::create([
        'tenant_id' => $tenant->id,
        'contact_id' => $contact->id,
        'invoice_type' => 'ch_qr_bill',
        'invoice_number' => $number,
        'date' => now()->toDateString(),
        'currency' => 'CHF',
        'total_net' => 100,
        'total_tax' => 8.1,
        'total_gross' => 108.1,
        'jurisdiction' => 'CH',
    ]);
    InvoiceQrBillData::create(['invoice_id' => $invoice->id, 'qr_iban' => 'CH4431999123000889012', 'scor_reference' => $scorReference, 'creditor_info' => ['name' => 'Alfa SA'], 'debtor_info' => ['name' => 'Cliente']]);
    app(TenantContext::class)->clear();

    return $invoice;
}

test('the auto-match of a SCOR reference never picks the QR data of an invoice of another tenant', function (): void {
    $scor = 'RF18 5390 0754 7034';
    // The row of the other tenant is written first, so that an unscoped lookup returns it before the own one.
    $foreign = leakInvoice($this->tenantB, 'CH-B-0001', $scor);
    $own = leakInvoice($this->tenantA, 'CH-A-0001', $scor);

    app(TenantContext::class)->setTenant($this->tenantA);
    $account = BankAccount::factory()->create(['tenant_id' => $this->tenantA->id]);
    $transaction = BankTransaction::create([
        'tenant_id' => $this->tenantA->id,
        'bank_account_id' => $account->id,
        'booking_date' => now()->toDateString(),
        'value_date' => now()->toDateString(),
        'amount' => 108.1,
        'currency' => 'CHF',
        'description' => 'QR payment',
        'reference' => $scor,
        'status' => 'unmatched',
    ]);
    app(TenantContext::class)->clear();

    [$tenant, $user] = leakOwner($this->tenantA);
    Sanctum::actingAs($user);

    $this->withHeader('X-Tenant-Id', $tenant->id)
        ->postJson('/api/v1/treasury/reconciliation/auto-match')
        ->assertOk()
        ->assertJsonPath('data.matched_invoices', 1);

    expect(Invoice::query()->withoutGlobalScopes()->find($own->id)->payment_status)->toBe('paid')
        ->and(Invoice::query()->withoutGlobalScopes()->find($foreign->id)->payment_status)->not->toBe('paid')
        ->and(BankTransaction::query()->withoutGlobalScopes()->find($transaction->id)->matched_invoice_id)->toBe($own->id);
});

test('updating a contact answers with the contact, not with a server error, and another tenant cannot reach it', function (): void {
    app(TenantContext::class)->setTenant($this->tenantA);
    $contact = Contact::create(['tenant_id' => $this->tenantA->id, 'company_name' => 'Cliente Alfa', 'country' => 'CH']);
    app(TenantContext::class)->clear();

    [$tenantA, $ownerA] = leakOwner($this->tenantA);
    Sanctum::actingAs($ownerA);
    $this->withHeader('X-Tenant-Id', $tenantA->id)
        ->putJson("/api/v1/crm/contacts/{$contact->id}", ['company_name' => 'Cliente Alfa SA'])
        ->assertOk()
        ->assertJsonPath('data.company_name', 'Cliente Alfa SA');

    [$tenantB, $ownerB] = leakOwner($this->tenantB);
    Sanctum::actingAs($ownerB);
    $this->withHeader('X-Tenant-Id', $tenantB->id)
        ->putJson("/api/v1/crm/contacts/{$contact->id}", ['company_name' => 'Rubato'])
        ->assertNotFound();
    $this->withHeader('X-Tenant-Id', $tenantB->id)
        ->patchJson("/api/v1/crm/contacts/{$contact->id}", ['company_name' => 'Rubato'])
        ->assertNotFound();

    expect(Contact::query()->withoutGlobalScopes()->find($contact->id)->company_name)->toBe('Cliente Alfa SA');
});
