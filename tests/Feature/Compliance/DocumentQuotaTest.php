<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

function invoicePayload(Tenant $tenant): array
{
    $contact = Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => 'Cliente', 'vat_number' => '12345678901']);

    return ['contact_id' => $contact->id, 'invoice_type' => 'standard', 'jurisdiction' => 'IT', 'date' => '2027-03-01', 'due_date' => '2027-04-01', 'currency' => 'EUR', 'items' => [['description' => 'Servizio', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22]]];
}

function commercialDocument()
{
    return test()->postJson('/api/v1/fiscal-retail/commercial-documents', ['lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1, 'tax_rate' => 22]]]);
}

beforeEach(function (): void {
    $this->travelTo('2027-03-02 10:00:00');
    $this->tenant = complianceTenant();
    openPeriod($this->tenant);
    actingAsMember($this->tenant);
});

test('without a limit nothing is enforced and the usage says it is off', function (): void {
    commercialDocument()->assertCreated();

    expect($this->getJson('/api/v1/settings/document-quota')->assertOk()->json('data'))->toMatchArray(['enabled' => false, 'limit' => null, 'used' => '0.1']);
});

test('points add up, an invoice counts 1 and a commercial document 0.1, with a 422 at the limit', function (): void {
    $this->tenant->update(['document_quota_points' => '1.2']);

    $this->postJson('/api/v1/sales/invoices', invoicePayload($this->tenant))->assertCreated();
    commercialDocument()->assertCreated();
    commercialDocument()->assertCreated();
    commercialDocument()->assertUnprocessable()->assertJsonValidationErrors('document_quota');
    $this->postJson('/api/v1/sales/invoices', invoicePayload($this->tenant))->assertUnprocessable()->assertJsonValidationErrors('document_quota');

    expect(Invoice::count())->toBe(1)
        ->and($this->getJson('/api/v1/settings/document-quota')->json('data'))->toMatchArray(['enabled' => true, 'limit' => '1.2', 'used' => '1.2', 'remaining' => '0.0']);
});

test('a new year starts from zero and voids do not consume points', function (): void {
    $this->tenant->update(['document_quota_points' => '0.1']);
    $document = commercialDocument()->assertCreated()->json('data');
    commercialDocument()->assertUnprocessable();

    $this->postJson("/api/v1/fiscal-retail/commercial-documents/{$document['id']}/void")->assertCreated();

    $this->travelTo('2028-01-02 10:00:00');
    commercialDocument()->assertCreated();
});

test('the limit is set by platform administrators only, for one tenant', function (): void {
    $this->putJson("/api/v1/admin/tenants/{$this->tenant->id}/document-quota", ['document_quota_points' => 500])->assertForbidden();

    $admin = User::create(['name' => 'Root', 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $admin->forceFill(['is_platform_admin' => true])->save();
    Sanctum::actingAs($admin);

    $this->putJson("/api/v1/admin/tenants/{$this->tenant->id}/document-quota", ['document_quota_points' => 500])->assertOk()->assertJsonPath('data.limit', '500.0');
    expect(complianceTenant('Beta')->document_quota_points)->toBeNull();
});
