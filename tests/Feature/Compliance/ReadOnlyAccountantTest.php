<?php

declare(strict_types=1);

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    actingAsMember($this->tenant, 'accountant_readonly');
});

test('the read-only accountant reads finance data', function (string $uri): void {
    expect($this->getJson($uri)->status())->toBe(200);
})->with([
    '/api/v1/treasury/payment-batches',
    '/api/v1/sales/invoices',
    '/api/v1/purchases/passive-invoices',
    '/api/v1/invoicing/invoices',
]);

test('the read-only accountant is refused on every write, even on routes that declare no module', function (string $method, string $uri): void {
    expect($this->json($method, $uri, [])->status())->toBe(403);
})->with([
    ['POST', '/api/v1/sales/invoices'],
    ['POST', '/api/v1/crm/contacts'],
    ['POST', '/api/v1/purchases/passive-invoices/receive-xml'],
    ['POST', '/api/v1/invoicing/invoices'],
    ['POST', '/api/v1/treasury/payment-batches'],
    ['PUT', '/api/v1/general-ledger/tax-settings'],
    ['DELETE', '/api/v1/sales/invoices/00000000-0000-0000-0000-000000000000'],
]);

test('the read-only accountant does not reach data closed to finance', function (): void {
    $this->getJson('/api/v1/hr/employees')->assertForbidden();
    $this->getJson('/api/v1/integrations/api-keys')->assertForbidden();
});

test('the existing accountant role keeps its write access', function (): void {
    actingAsMember($this->tenant, 'accountant');

    expect($this->postJson('/api/v1/treasury/payment-batches', [])->status())->toBe(422);
});

test('a write refused to the role leaves no record', function (): void {
    $this->postJson('/api/v1/crm/contacts', ['company_name' => 'Nope', 'type' => 'client'])->assertForbidden();

    expect(Contact::count())->toBe(0);
});
