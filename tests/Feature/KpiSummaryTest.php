<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'FatturaFacile Italy SRL', 'legal_name' => 'FatturaFacile Italy SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->otherTenant = Tenant::create(['name' => 'Other SRL', 'legal_name' => 'Other SRL', 'country' => 'IT', 'default_currency' => 'EUR']);

    $this->user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    $this->user->tenants()->attach($this->otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);

    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Mario Rossi', 'email' => 'mario@example.com', 'type' => 'customer', 'country' => 'IT']);
    $this->foreignCustomer = Contact::create(['tenant_id' => $this->otherTenant->id, 'company_name' => 'Foreign Buyer', 'email' => 'buyer@example.com', 'type' => 'customer', 'country' => 'IT']);
});

function kpiSummaryInvoice(string $number, string $status, float $gross, float $paid, ?string $tenantId = null): Invoice
{
    $tenantId ??= test()->tenant->id;

    return Invoice::create([
        'tenant_id' => $tenantId,
        'contact_id' => $tenantId === test()->tenant->id ? test()->customer->id : test()->foreignCustomer->id,
        'invoice_number' => $number, 'date' => '2026-03-01', 'due_date' => '2026-03-31',
        'total_net' => round($gross / 1.22, 2), 'total_tax' => round($gross - $gross / 1.22, 2), 'total_gross' => $gross,
        'total_paid' => $paid, 'payment_status' => $status, 'invoice_type' => 'standard',
    ]);
}

/**
 * @return array<string, mixed>
 */
function kpiSummary(?string $tenantId = null): array
{
    return test()->withHeader('X-Tenant-Id', $tenantId ?? test()->tenant->id)
        ->getJson('/api/v1/dashboard/kpi-summary')
        ->assertOk()
        ->json('data.kpis');
}

it('returns zero KPIs when there are no invoices', function (): void {
    $kpis = kpiSummary();

    expect($kpis['total_unpaid'])->toEqual(0.0)
        ->and($kpis['total_revenue'])->toEqual(0.0)
        ->and($kpis['invoices_count'])->toBe(0);
});

it('counts an unpaid invoice gross as receivable and not as revenue', function (): void {
    kpiSummaryInvoice('U-1', 'unpaid', 122.00, 0);

    $kpis = kpiSummary();

    expect($kpis['total_unpaid'])->toEqual(122.0)
        ->and($kpis['total_revenue'])->toEqual(0.0);
});

it('counts the residual of a partially paid invoice as receivable and the collected amount as revenue', function (): void {
    kpiSummaryInvoice('P-1', 'partially_paid', 122.00, 40.00);

    $kpis = kpiSummary();

    expect($kpis['total_unpaid'])->toEqual(82.0)
        ->and($kpis['total_revenue'])->toEqual(40.0);
});

it('counts an invoice paid through RecordPayment as revenue only', function (): void {
    kpiSummaryInvoice('F-1', 'paid', 122.00, 122.00);

    $kpis = kpiSummary();

    expect($kpis['total_unpaid'])->toEqual(0.0)
        ->and($kpis['total_revenue'])->toEqual(122.0);
});

it('keeps the gross of a legacy paid invoice with no recorded payments in revenue', function (): void {
    kpiSummaryInvoice('L-1', 'paid', 122.00, 0);

    $kpis = kpiSummary();

    expect($kpis['total_unpaid'])->toEqual(0.0)
        ->and($kpis['total_revenue'])->toEqual(122.0);
});

it('clamps the receivable of an overpaid partially paid invoice to zero', function (): void {
    kpiSummaryInvoice('O-1', 'partially_paid', 122.00, 150.00);

    $kpis = kpiSummary();

    expect($kpis['total_unpaid'])->toEqual(0.0)
        ->and($kpis['total_revenue'])->toEqual(150.0);
});

it('sums unpaid gross and partial residuals together', function (): void {
    kpiSummaryInvoice('U-1', 'unpaid', 122.00, 0);
    kpiSummaryInvoice('P-1', 'partially_paid', 122.00, 40.00);
    kpiSummaryInvoice('F-1', 'paid', 244.00, 244.00);

    $kpis = kpiSummary();

    expect($kpis['total_unpaid'])->toEqual(204.0)
        ->and($kpis['total_revenue'])->toEqual(284.0)
        ->and($kpis['invoices_count'])->toBe(3);
});

it('ignores invoices of another tenant', function (): void {
    kpiSummaryInvoice('U-1', 'unpaid', 122.00, 0);
    kpiSummaryInvoice('X-1', 'unpaid', 1000.00, 0, test()->otherTenant->id);
    kpiSummaryInvoice('X-2', 'partially_paid', 500.00, 100.00, test()->otherTenant->id);
    kpiSummaryInvoice('X-3', 'paid', 300.00, 300.00, test()->otherTenant->id);

    $kpis = kpiSummary();

    expect($kpis['total_unpaid'])->toEqual(122.0)
        ->and($kpis['total_revenue'])->toEqual(0.0)
        ->and($kpis['invoices_count'])->toBe(1);
});
