<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\ExchangeRate;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\ConsolidationMember;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\Intercompany\Models\PartyLink;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * Builders of the intercompany tests (Mod 021): a consolidation group of two EUR entities (a parent and an 80 % subsidiary)
 * with the contacts and party links that let them invoice each other, an outsider entity of another group, an accountant
 * who can read the ledger of both entities, and helpers for sales invoices and small ledgers.
 */
function icUser(Tenant $tenant, string $role = 'accountant'): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => Str::password(16).chr(random_int(65, 90)).chr(random_int(97, 122)).random_int(0, 9)]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function icGrant(User $user, Tenant $tenant, string $role = 'accountant'): void
{
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);
}

function icActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

function icTenant(string $name, string $currency = 'EUR'): Tenant
{
    return Tenant::create(['name' => $name, 'legal_name' => "{$name} SpA", 'country' => $currency === 'CHF' ? 'CH' : 'IT', 'default_currency' => $currency]);
}

/**
 * Parent, 80 % subsidiary, an outsider, the group with party links in both directions and the accountant of the group.
 */
function icGroupSetUp(object $test): void
{
    $test->parent = icTenant('Capogruppo');
    $test->italia = icTenant('Italia');
    $test->outsider = icTenant('Estranea');

    $test->accountant = icUser($test->parent);
    icGrant($test->accountant, $test->italia);
    $test->outsiderAccountant = icUser($test->outsider);

    $test->group = ConsolidationGroup::withoutGlobalScopes()->create(['tenant_id' => $test->parent->id, 'name' => 'Gruppo Alfa', 'group_currency' => 'EUR', 'is_active' => true]);

    foreach ([[$test->parent, 100, true], [$test->italia, 80, false]] as [$tenant, $share, $isParent]) {
        ConsolidationMember::withoutGlobalScopes()->create(['tenant_id' => $test->parent->id, 'group_id' => $test->group->id, 'member_tenant_id' => $tenant->id, 'ownership_percentage' => $share, 'consolidation_method' => 'full', 'is_parent' => $isParent]);
    }

    // The contacts that stand for each entity in the books of the other one.
    $test->italiaAsCustomer = Contact::create(['tenant_id' => $test->parent->id, 'type' => 'client', 'company_name' => 'Italia', 'country' => 'IT']);
    $test->italiaAsSupplier = Contact::create(['tenant_id' => $test->parent->id, 'type' => 'supplier', 'company_name' => 'Italia', 'country' => 'IT']);
    $test->parentAsSupplier = Contact::create(['tenant_id' => $test->italia->id, 'type' => 'supplier', 'company_name' => 'Capogruppo', 'country' => 'IT']);
    $test->parentAsCustomer = Contact::create(['tenant_id' => $test->italia->id, 'type' => 'client', 'company_name' => 'Capogruppo', 'country' => 'IT']);

    foreach ([
        [$test->parent, $test->italia, $test->italiaAsCustomer, 'customer'],
        [$test->italia, $test->parent, $test->parentAsSupplier, 'supplier'],
        [$test->italia, $test->parent, $test->parentAsCustomer, 'customer'],
        [$test->parent, $test->italia, $test->italiaAsSupplier, 'supplier'],
    ] as [$entity, $counterparty, $contact, $role]) {
        PartyLink::withoutGlobalScopes()->create(['tenant_id' => $test->parent->id, 'group_id' => $test->group->id, 'entity_tenant_id' => $entity->id, 'counterparty_tenant_id' => $counterparty->id, 'contact_id' => $contact->id, 'role' => $role]);
    }

    icActAs($test->accountant, $test->parent);
}

/**
 * A posted sales invoice (or credit note) of an entity to a contact.
 *
 * @param  array<string, mixed>  $overrides
 */
function icInvoice(Tenant $tenant, Contact $contact, array $overrides = []): SalesInvoice
{
    $invoice = SalesInvoice::factory()->posted()->create(array_merge([
        'tenant_id' => $tenant->id,
        'contact_id' => $contact->id,
        'invoice_number' => 'IC-'.Str::upper(Str::random(6)),
        'date' => '2027-03-10',
        'due_date' => '2027-04-10',
        'currency' => 'EUR',
        'total_net' => 1000,
        'total_tax' => 220,
        'total_gross' => 1220,
        'subtotal' => 1000,
        'discount_amount' => 0,
        'receivable_amount' => 1220,
        'total_paid' => 0,
        'payment_status' => 'unpaid',
    ], $overrides));

    return $invoice;
}

/**
 * Sets what the counterparty has paid on an invoice, without going through the protected invoice model.
 */
function icPay(SalesInvoice $invoice, string $paid): void
{
    DB::table('invoices')->where('id', $invoice->id)->update(['total_paid' => $paid, 'payment_status' => bccomp($paid, (string) $invoice->total_gross, 2) >= 0 ? 'paid' : 'partial']);
}

/**
 * Mirrors the posted documents of the group through the API and returns the first transaction.
 *
 * @return array<string, mixed>
 */
function icScan(object $test): array
{
    icActAs($test->accountant, $test->parent);

    return test()->postJson("/api/v1/intercompany/groups/{$test->group->id}/transactions/scan")->assertOk()->json('data');
}

function icPassive(Tenant $tenant, Contact $supplier, array $overrides = []): PassiveInvoice
{
    return PassiveInvoice::create(array_merge([
        'tenant_id' => $tenant->id,
        'supplier_id' => $supplier->id,
        'invoice_number' => 'P-'.Str::upper(Str::random(6)),
        'date' => '2027-03-10',
        'due_date' => '2027-04-10',
        'document_type' => 'TD01',
        'total_amount' => 1220,
        'total_net' => 1000,
        'total_tax' => 220,
        'currency' => 'EUR',
        'payment_status' => 'unpaid',
        'total_paid' => 0,
    ], $overrides));
}

function icRates(): void
{
    foreach ([['2027-01-01', '0.950000'], ['2027-06-30', '1.050000']] as [$date, $rate]) {
        ExchangeRate::createGlobal(['source' => 'CHF', 'target' => 'EUR', 'rate' => $rate, 'effective_date' => $date]);
    }
}

/**
 * The same chart for every entity of a consolidation scenario; opens the fiscal year 2027.
 *
 * @return array<string, ChartOfAccount>
 */
function icChart(Tenant $tenant): array
{
    app(TenantContext::class)->setTenant($tenant);
    app(OpenFiscalYearAction::class)->execute((string) $tenant->id, 2027);
    app(TenantContext::class)->clear();

    $accounts = [];

    foreach ([
        'bank' => ['1000', 'asset', 'debit'], 'receivable' => ['1150', 'asset', 'debit'], 'inventory' => ['1300', 'asset', 'debit'],
        'fixed' => ['1600', 'asset', 'debit'], 'invest' => ['1500', 'asset', 'debit'],
        'payable' => ['2150', 'liability', 'credit'], 'capital' => ['3000', 'equity', 'credit'],
        'sales' => ['4000', 'revenue', 'credit'], 'ic_sales' => ['4100', 'revenue', 'credit'],
        'cost' => ['6000', 'expense', 'debit'], 'ic_cost' => ['6100', 'expense', 'debit'], 'dep' => ['6200', 'expense', 'debit'],
    ] as $key => [$code, $type, $normal]) {
        $accounts[$key] = ChartOfAccount::factory()->create([
            'tenant_id' => $tenant->id, 'company_id' => null, 'account_code' => $code, 'name' => "Account {$code}",
            'account_type' => $type, 'account_class' => $type, 'normal_balance' => $normal, 'is_active' => true, 'allows_manual_posting' => true,
        ]);
    }

    return $accounts;
}

function icPost(Tenant $tenant, string $date, ChartOfAccount $debit, ChartOfAccount $credit, string $amount): void
{
    app(TenantContext::class)->setTenant($tenant);
    $period = FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->firstOrFail();

    app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => $tenant->id, 'fiscal_period_id' => $period->id, 'posting_date' => $date, 'description' => 'Intercompany test posting', 'bank_balance_managed' => true,
        'lines' => [
            ['account_id' => $debit->id, 'debit_amount' => $amount, 'credit_amount' => '0'],
            ['account_id' => $credit->id, 'debit_amount' => '0', 'credit_amount' => $amount],
        ],
    ]);
    app(TenantContext::class)->clear();
}
