<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

function salesFact(Tenant $tenant, string $date, string $net, array $contact = [], string $type = 'standard'): Invoice
{
    $customer = Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => 'Cliente', ...$contact]);
    $invoice = new Invoice(['contact_id' => $customer->id, 'invoice_type' => $type, 'invoice_number' => Str::random(8), 'date' => $date, 'due_date' => $date, 'currency' => 'EUR', 'total_net' => $net, 'total_tax' => 0, 'total_gross' => $net, 'total_paid' => 0, 'payment_status' => 'unpaid']);
    $invoice->tenant_id = $tenant->id;
    $invoice->save();

    return $invoice;
}

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    actingAsMember($this->tenant);
    $this->march = salesFact($this->tenant, '2027-03-05', '150.00', ['vat_number' => '12345678901']);
    salesFact($this->tenant, '2027-03-06', '-50.00', [], 'credit_note');
    salesFact($this->tenant, '2027-03-07', '999.00', [], 'proforma');
    salesFact($this->tenant, '2026-03-05', '200.00');
});

test('revenue compares each month with the year before, credit notes subtract and proforma do not count', function (): void {
    $data = $this->getJson('/api/v1/analytics/revenue-comparison?year=2027')->assertOk()->json('data');

    expect($data['months'][2])->toBe(['month' => 3, 'current' => '100.00', 'previous' => '200.00', 'change_percent' => '-50.00'])
        ->and($data['months'][0]['change_percent'])->toBeNull()
        ->and($data['totals']['current'])->toBe('100.00');
});

test('revenue leaves out cancelled, void and placeholder drafts and converts other currencies with the stored rate', function (): void {
    salesFact($this->tenant, '2027-03-08', '500.00')->forceFill(['status' => 'cancelled'])->save();
    salesFact($this->tenant, '2027-03-08', '500.00')->forceFill(['status' => 'void'])->save();
    salesFact($this->tenant, '2027-03-08', '500.00')->forceFill(['status' => 'draft', 'invoice_number' => 'DRAFT-ABC123'])->save();
    salesFact($this->tenant, '2027-03-09', '100.00')->forceFill(['status' => 'posted', 'currency' => 'CHF', 'exchange_rate' => '1.050000'])->save();

    $data = $this->getJson('/api/v1/analytics/revenue-comparison?year=2027')->assertOk()->json('data');

    expect(Tenant::query()->findOrFail($this->tenant->id)->default_currency)->toBe('EUR')
        ->and($data['months'][2]['current'])->toBe('205.00');
});

test('the breakdown by customer type separates businesses and private customers', function (): void {
    $rows = collect($this->getJson('/api/v1/analytics/breakdown/customer-type?year=2027')->assertOk()->json('data.rows'))->keyBy('key');

    expect($rows['business']['current'])->toBe('150.00')
        ->and($rows['private']['current'])->toBe('-50.00')
        ->and($rows['private']['previous'])->toBe('200.00');
});

test('the breakdown by product uses the invoice lines', function (): void {
    InvoiceItem::create(['invoice_id' => $this->march->id, 'description' => 'Consulenza', 'quantity' => 1, 'unit_price' => 150, 'tax_rate' => 0, 'line_total' => 150]);

    $rows = $this->getJson('/api/v1/analytics/breakdown/product?year=2027')->assertOk()->json('data.rows');

    expect($rows)->toHaveCount(1)->and($rows[0])->toMatchArray(['label' => 'Consulenza', 'current' => '150.00']);
});

test('the breakdown of purchases goes by supplier, net of credit notes', function (): void {
    $supplier = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'supplier', 'company_name' => 'Fornitore']);

    foreach ([['TD01', '100.00'], ['TD04', '30.00']] as [$type, $net]) {
        PassiveInvoice::withoutEvents(function () use ($supplier, $type, $net): void {
            $invoice = new PassiveInvoice(['supplier_id' => $supplier->id, 'invoice_number' => Str::random(6), 'date' => '2027-01-10', 'total_amount' => $net, 'total_net' => $net, 'total_tax' => 0, 'currency' => 'EUR', 'document_type' => $type, 'payment_status' => 'unpaid', 'document_hash' => Str::random(40)]);
            $invoice->tenant_id = $this->tenant->id;
            $invoice->save();
        });
    }

    $rows = $this->getJson('/api/v1/analytics/breakdown/purchases?year=2027')->assertOk()->json('data.rows');

    expect($rows[0])->toMatchArray(['label' => 'Fornitore', 'current' => '70.00']);
});

test('analytics are isolated per tenant and closed to members', function (): void {
    actingAsMember(complianceTenant('Beta'));
    expect($this->getJson('/api/v1/analytics/revenue-comparison?year=2027')->json('data.totals.current'))->toBe('0.00');

    actingAsMember($this->tenant, 'member');
    $this->getJson('/api/v1/analytics/revenue-comparison')->assertForbidden();
});
