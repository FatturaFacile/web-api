<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Integrations\Models\ApiKey;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

function paymentOf(Tenant $tenant, string $amount, string $paidAt, ?string $notes = null): Payment
{
    $contact = Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => 'Cliente']);
    $invoice = new Invoice(['contact_id' => $contact->id, 'invoice_type' => 'standard', 'invoice_number' => 'FT-'.Str::random(5), 'date' => '2027-01-10', 'due_date' => '2027-02-10', 'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122, 'total_paid' => 0, 'payment_status' => 'unpaid']);
    $invoice->tenant_id = $tenant->id;
    $invoice->save();

    $payment = new Payment(['invoice_id' => $invoice->id, 'amount' => $amount, 'paid_at' => $paidAt, 'payment_method' => 'MP05', 'reference' => 'CRO-'.Str::random(4), 'notes' => $notes]);
    $payment->tenant_id = $tenant->id;
    $payment->save();

    return $payment;
}

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    $this->admin = actingAsMember($this->tenant);
    paymentOf($this->tenant, '122.00', '2027-02-01', 'riservato interno');
    paymentOf($this->tenant, '50.50', '2027-03-15');
    paymentOf(complianceTenant('Beta'), '999.00', '2027-02-02');
});

test('the CSV of payments is the tenant only, by payment date, without the free-text notes', function (): void {
    $csv = $this->get('/api/v1/exports/csv/payments')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();

    expect($csv)->toContain('"id";"invoice_id";"amount";"paid_at";"payment_method";"reference"')
        ->toContain('"122.00"')->toContain('"50.50"')->not->toContain('999.00')->not->toContain('riservato');

    $march = $this->get('/api/v1/exports/csv/payments?from=2027-03-01&to=2027-03-31')->getContent();
    expect($march)->toContain('"50.50"')->not->toContain('"122.00"');
});

test('the reporting feed serves payments to a key with the reporting scope', function (): void {
    $secret = ApiKey::TokenPrefix.Str::random(48);
    ApiKey::create(['tenant_id' => $this->tenant->id, 'name' => 'BI', 'prefix' => substr($secret, 0, 12), 'key_hash' => hash('sha256', $secret), 'scopes' => ['reporting.read']]);
    app('auth')->forgetGuards();
    app(TenantContext::class)->clear();

    $body = $this->withHeaders(['Authorization' => "Bearer {$secret}", 'X-Tenant-Id' => ''])->getJson('/api/v1/reporting/feed/payments?from=2027-02-01&to=2027-02-28')->assertOk()->json();

    expect($body['@odata.count'])->toBe(1)->and($body['value'][0]['amount'])->toBe('122.00')
        ->and(array_keys($body['value'][0]))->not->toContain('tenant_id')->not->toContain('notes');
});

test('payment exports validate the period and refuse unknown datasets and read-less roles', function (): void {
    $this->get('/api/v1/exports/csv/payments?from=31-12-2027', ['Accept' => 'application/json'])->assertUnprocessable();
    $this->getJson('/api/v1/exports/csv/unknown')->assertNotFound();

    actingAsMember($this->tenant, 'member');
    $this->getJson('/api/v1/exports/csv/payments')->assertForbidden();
});
