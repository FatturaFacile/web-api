<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Medico Neri', 'legal_name' => 'Dott. Neri', 'vat_number' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->user = User::create(['name' => 'Paolo Neri', 'email' => 'paolo@neri.example', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    app(TenantContext::class)->setTenant($this->tenant);
    $this->patient = Contact::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Anna', 'last_name' => 'Gialli', 'country' => 'IT']);
});

function stampReportApi()
{
    Sanctum::actingAs(test()->user);

    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

function stampReportInvoice(string $number, string $date, bool $stamp = true, string $type = 'sdi_electronic', ?Tenant $tenant = null, ?Contact $contact = null): Invoice
{
    $tenant ??= test()->tenant;

    return Invoice::create([
        'tenant_id' => $tenant->id,
        'contact_id' => ($contact ?? test()->patient)->id,
        'invoice_type' => $type,
        'invoice_number' => $number,
        'date' => $date,
        'currency' => 'EUR',
        'jurisdiction' => 'IT',
        'total_net' => '100.00',
        'total_gross' => $stamp ? '102.00' : '100.00',
        'stamp_duty_applied' => $stamp,
        'stamp_duty_amount' => $stamp ? '2.00' : null,
    ]);
}

test('quarterly report counts the invoices with stamp duty and gives the F24 data', function (): void {
    stampReportInvoice('F-1', '2026-01-10');
    stampReportInvoice('F-2', '2026-03-31');
    stampReportInvoice('F-3', '2026-02-15', stamp: false);
    stampReportInvoice('NC-1', '2026-02-20', type: 'credit_note');
    stampReportInvoice('F-4', '2026-04-01');

    stampReportApi()->getJson('/api/v1/tax/stamp-duty/quarterly?year=2026&quarter=1')
        ->assertOk()
        ->assertJsonPath('data.year', 2026)
        ->assertJsonPath('data.quarter', 1)
        ->assertJsonPath('data.count', 2)
        ->assertJsonPath('data.total_amount', '4.00')
        ->assertJsonPath('data.f24', ['tributo' => '2521', 'due_date' => '2026-05-31', 'deferrable' => true])
        ->assertJsonPath('data.invoices.0.number', 'F-1')
        ->assertJsonPath('data.invoices.0.customer', 'Anna Gialli')
        ->assertJsonPath('data.invoices.0.amount', '2.00')
        ->assertJsonPath('data.invoices.1.number', 'F-2');
});

test('each quarter has its own tributo and due date', function (int $quarter, string $tributo, string $dueDate, bool $deferrable): void {
    stampReportApi()->getJson("/api/v1/tax/stamp-duty/quarterly?year=2026&quarter={$quarter}")
        ->assertOk()
        ->assertJsonPath('data.count', 0)
        ->assertJsonPath('data.total_amount', '0.00')
        ->assertJsonPath('data.f24', ['tributo' => $tributo, 'due_date' => $dueDate, 'deferrable' => $deferrable]);
})->with([
    'Q2' => [2, '2522', '2026-09-30', true],
    'Q3' => [3, '2523', '2026-11-30', false],
    'Q4' => [4, '2524', '2027-02-28', false],
]);

test('a quarter outside 1-4 is rejected', function (int $quarter): void {
    stampReportApi()->getJson("/api/v1/tax/stamp-duty/quarterly?year=2026&quarter={$quarter}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quarter');
})->with([0, 5]);

test('the report downloads as CSV', function (): void {
    stampReportInvoice('F-1', '2026-01-10');

    $response = stampReportApi()->get('/api/v1/tax/stamp-duty/quarterly?year=2026&quarter=1&format=csv')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->getContent())->toContain('F-1')
        ->and($response->getContent())->toContain('2521');
});

test('stamp duty of other tenants is never reported', function (): void {
    stampReportInvoice('F-1', '2026-01-10');

    $other = Tenant::create(['name' => 'Other', 'legal_name' => 'Other SRL', 'vat_number' => '01234567890', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($other);
    $otherContact = Contact::create(['tenant_id' => $other->id, 'company_name' => 'Altro', 'country' => 'IT']);
    stampReportInvoice('X-1', '2026-01-11', tenant: $other, contact: $otherContact);
    stampReportInvoice('X-2', '2026-01-12', tenant: $other, contact: $otherContact);

    stampReportApi()->getJson('/api/v1/tax/stamp-duty/quarterly?year=2026&quarter=1')
        ->assertOk()
        ->assertJsonPath('data.count', 1)
        ->assertJsonPath('data.total_amount', '2.00');
});
