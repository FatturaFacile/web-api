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
    return test()->withHeader('X-Tenant-ID', test()->tenant->id);
}

test('legacy endpoints answer with the X-Deprecated header and a link to their successor', function (string $method, string $uri, string $successor): void {
    $response = legacyApi()->json($method, $uri, []);

    expect($response->headers->get('X-Deprecated'))->toBe('true')
        ->and($response->headers->get('Link'))->toBe("<{$successor}>; rel=\"successor-version\"");
})->with(function (): array {
    $id = '3f6b2a3e-7a52-4a41-9d3c-0f6f2d1c1a11';

    return [
        'pos checkout' => ['POST', '/api/v1/retail/pos/checkout', '/api/v1/retail/pos/sales'],
        'pos rt sync' => ['POST', '/api/v1/retail/pos/rt-sync', '/api/v1/retail/pos/sales/{id}/rt-transmit'],
        'work order sign' => ['POST', "/api/v1/field-service/work-orders/{$id}/sign", '/api/v1/field-service/work-orders/{id}/signature'],
        'passive invoice import' => ['POST', '/api/v1/purchases/passive-invoices/import-sdi', '/api/v1/purchases/passive-invoices/receive-xml'],
        'camt import' => ['POST', '/api/v1/treasury/import-camt', '/api/v1/sales/payment-imports'],
        'generate qr bill' => ['POST', "/api/v1/sales/invoices/{$id}/generate-qr-bill", '/api/v1/sales/invoices/{id}/qr-bill'],
        'download qr bill' => ['GET', "/api/v1/sales/invoices/{$id}/download-qr-bill", '/api/v1/sales/invoices/{id}/qr-bill'],
    ];
});

test('a deprecated endpoint keeps working: the header is added to successful responses too', function (): void {
    $terminal = legacyApi()->postJson('/api/v1/retail/pos/terminals', ['terminal_code' => 'T-01', 'name' => 'Front desk'])->json('data.id');
    $session = legacyApi()->postJson('/api/v1/retail/pos/sessions', ['pos_terminal_id' => $terminal, 'opening_float' => 50])->json('data.id');

    $response = legacyApi()->postJson('/api/v1/retail/pos/checkout', [
        'pos_session_id' => $session, 'total_net' => 10, 'total_tax' => 2.2, 'total_gross' => 12.2, 'payment_method' => 'cash',
        'items' => [['description' => 'Widget', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 22, 'line_total' => 10]],
    ])->assertCreated();

    expect($response->headers->get('X-Deprecated'))->toBe('true')->and($response->json('data.receipt_number'))->toStartWith('REC-');
});

test('error responses of deprecated endpoints are flagged as well', function (): void {
    legacyApi()->postJson('/api/v1/retail/pos/checkout', [])->assertUnprocessable()->assertHeader('X-Deprecated', 'true');
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
