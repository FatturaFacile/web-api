<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'FatturaFacile Italy SRL', 'legal_name' => 'FatturaFacile Italy SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);
});

function legacyApi()
{
    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

test('legacy endpoints answer with the X-Deprecated header and a link to their successor', function (string $method, string $uri, string $successor): void {
    $response = legacyApi()->json($method, $uri, []);

    expect($response->headers->get('X-Deprecated'))->toBe('true')
        ->and($response->headers->get('Link'))->toBe("<{$successor}>; rel=\"successor-version\"");
})->with(function (): array {
    $id = '3f6b2a3e-7a52-4a41-9d3c-0f6f2d1c1a11';

    return [
        'work order sign' => ['POST', "/api/v1/field-service/work-orders/{$id}/sign", '/api/v1/field-service/work-orders/{id}/signature'],
        'passive invoice import' => ['POST', '/api/v1/purchases/passive-invoices/import-sdi', '/api/v1/purchases/passive-invoices/receive-xml'],
        'camt import' => ['POST', '/api/v1/treasury/import-camt', '/api/v1/sales/payment-imports'],
        'generate qr bill' => ['POST', "/api/v1/sales/invoices/{$id}/generate-qr-bill", '/api/v1/sales/invoices/{id}/qr-bill'],
        'download qr bill' => ['GET', "/api/v1/sales/invoices/{$id}/download-qr-bill", '/api/v1/sales/invoices/{id}/qr-bill'],
        'legacy chart of accounts' => ['GET', '/api/v1/accounting/chart-of-accounts', '/api/v1/general-ledger/accounts'],
        'legacy ledger entries' => ['GET', '/api/v1/accounting/ledger-entries', '/api/v1/general-ledger/journals'],
        'legacy vat settlements' => ['GET', '/api/v1/accounting/vat-settlements', '/api/v1/general-ledger/tax-settlements'],
        'stock adjustment' => ['POST', '/api/v1/inventory/stock-adjustments', '/api/v1/inventory/adjustments'],
    ];
});

test('a deprecated endpoint keeps working: the header is added to responses', function (): void {
    $response = legacyApi()->getJson('/api/v1/sales/invoices/'.Str::uuid().'/download-qr-bill');

    expect($response->headers->get('X-Deprecated'))->toBe('true')
        ->and($response->headers->get('Link'))->toContain('qr-bill');
});

test('error responses of deprecated endpoints are flagged as well', function (): void {
    legacyApi()->getJson('/api/v1/sales/invoices/'.Str::uuid().'/download-qr-bill')->assertNotFound()->assertHeader('X-Deprecated', 'true');
});

test('the replacement endpoints are not flagged', function (string $method, string $uri): void {
    $response = legacyApi()->json($method, $uri, []);

    expect($response->headers->has('X-Deprecated'))->toBeFalse()->and($response->headers->has('Link'))->toBeFalse();
})->with([
    'pos sales' => ['POST', '/api/v1/retail/pos/sales'],
    'payment imports' => ['POST', '/api/v1/sales/payment-imports'],
    'receive xml' => ['POST', '/api/v1/purchases/passive-invoices/receive-xml'],
    'invoice listing' => ['GET', '/api/v1/sales/invoices'],
    'pos session listing' => ['GET', '/api/v1/retail/pos/sessions'],
]);
