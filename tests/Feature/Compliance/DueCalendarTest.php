<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

function dueInvoice(Tenant $tenant, array $attributes): Invoice
{
    $contact = Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => 'Cliente']);
    $invoice = new Invoice(['contact_id' => $contact->id, 'invoice_type' => 'invoice', 'invoice_number' => Str::random(6), 'date' => '2027-01-01', 'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122, 'total_paid' => 0, 'payment_status' => 'unpaid', ...$attributes]);
    $invoice->tenant_id = $tenant->id;
    $invoice->save();

    return $invoice;
}

function duePassive(Tenant $tenant, array $attributes): PassiveInvoice
{
    $supplier = Contact::create(['tenant_id' => $tenant->id, 'type' => 'supplier', 'company_name' => 'Fornitore']);

    return PassiveInvoice::withoutEvents(static function () use ($tenant, $supplier, $attributes): PassiveInvoice {
        $invoice = new PassiveInvoice(['supplier_id' => $supplier->id, 'invoice_number' => Str::random(6), 'date' => '2027-01-01', 'total_amount' => 61, 'total_net' => 50, 'total_tax' => 11, 'total_paid' => 0, 'currency' => 'EUR', 'document_type' => 'TD01', 'payment_status' => 'unpaid', 'document_hash' => Str::random(40), ...$attributes]);
        $invoice->tenant_id = $tenant->id;
        $invoice->save();

        return $invoice;
    });
}

beforeEach(function (): void {
    $this->travelTo('2027-03-15 10:00:00');
    $this->tenant = complianceTenant();
    actingAsMember($this->tenant);
    dueInvoice($this->tenant, ['invoice_number' => 'IN-1', 'due_date' => '2027-03-10', 'total_paid' => 22, 'payment_status' => 'partially_paid']);
    dueInvoice($this->tenant, ['invoice_number' => 'IN-PAID', 'due_date' => '2027-03-12', 'total_paid' => 122, 'payment_status' => 'paid']);
    dueInvoice($this->tenant, ['invoice_number' => 'IN-PRO', 'due_date' => '2027-03-12', 'invoice_type' => 'proforma']);
    duePassive($this->tenant, ['invoice_number' => 'OUT-1', 'due_date' => '2027-03-20']);
    duePassive($this->tenant, ['invoice_number' => 'OUT-NC', 'due_date' => '2027-03-20', 'document_type' => 'TD04']);
});

test('receivables and payables come in one list by due date, with what is still open', function (): void {
    $data = $this->getJson('/api/v1/analytics/due-calendar?from=2027-03-01&to=2027-03-31')->assertOk()->json('data');

    expect(array_column($data['entries'], 'document_number'))->toBe(['IN-1', 'OUT-1'])
        ->and($data['entries'][0])->toMatchArray(['direction' => 'in', 'outstanding' => '100.00', 'is_overdue' => true])
        ->and($data['entries'][1])->toMatchArray(['direction' => 'out', 'outstanding' => '61.00', 'is_overdue' => false])
        ->and($data['totals'])->toBe(['to_collect' => '100.00', 'to_pay' => '61.00', 'balance' => '39.00']);
});

test('filters narrow the list', function (string $query, array $expected): void {
    $entries = $this->getJson("/api/v1/analytics/due-calendar?from=2027-03-01&to=2027-03-31&{$query}")->assertOk()->json('data.entries');

    expect(array_column($entries, 'document_number'))->toBe($expected);
})->with([
    ['direction=in', ['IN-1']],
    ['direction=out', ['OUT-1']],
    ['overdue_only=1', ['IN-1']],
]);

test('the period is required to be sane', function (): void {
    $this->getJson('/api/v1/analytics/due-calendar?from=2027-03-31&to=2027-03-01')->assertUnprocessable();
    $this->getJson('/api/v1/analytics/due-calendar?from=2025-01-01&to=2027-03-01')->assertStatus(422);
});

test('the calendar is isolated per tenant and closed to members', function (): void {
    actingAsMember(complianceTenant('Beta'));
    expect($this->getJson('/api/v1/analytics/due-calendar?from=2027-03-01&to=2027-03-31')->json('data.entries'))->toBe([]);

    actingAsMember($this->tenant, 'member');
    $this->getJson('/api/v1/analytics/due-calendar')->assertForbidden();

    actingAsMember($this->tenant, 'accountant_readonly');
    $this->getJson('/api/v1/analytics/due-calendar')->assertOk();
});
