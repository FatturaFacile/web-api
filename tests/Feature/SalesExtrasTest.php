<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');

    $this->tenant = Tenant::create([
        'name' => 'FatturaFacile Italy SRL',
        'legal_name' => 'FatturaFacile Italy SRL',
        'vat_number' => '00811720580',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    $user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);

    $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Milan Customer SpA', 'country' => 'IT']);

    $this->payload = [
        'contact_id' => $this->contact->id,
        'date' => '2026-03-10',
        'currency' => 'EUR',
        'items' => [
            ['description' => 'Widget', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22],
            ['description' => 'Service', 'quantity' => 1, 'unit_price' => 200, 'tax_rate' => 10],
        ],
    ];
});

function extrasApi()
{
    return test()->withHeader('X-Tenant-ID', test()->tenant->id);
}

test('a quote can be invoiced directly, once, and not when rejected', function (): void {
    $quoteId = extrasApi()->postJson('/api/v1/sales/quotes', $this->payload)->json('data.id');

    $invoice = extrasApi()->postJson("/api/v1/sales/quotes/{$quoteId}/invoice")->assertCreated();
    expect((float) $invoice->json('data.total_net'))->toEqual(300.0)
        ->and(extrasApi()->getJson("/api/v1/sales/quotes/{$quoteId}")->json('data.status'))->toBe('accepted');

    extrasApi()->postJson("/api/v1/sales/quotes/{$quoteId}/invoice")->assertUnprocessable();

    $rejected = extrasApi()->postJson('/api/v1/sales/quotes', array_merge($this->payload, ['status' => 'rejected']))->json('data.id');
    extrasApi()->postJson("/api/v1/sales/quotes/{$rejected}/invoice")->assertUnprocessable();
    extrasApi()->postJson("/api/v1/sales/quotes/{$rejected}/delivery-note")->assertUnprocessable();
});

test('a delivery note created from a quote hides prices unless requested', function (): void {
    $quoteId = extrasApi()->postJson('/api/v1/sales/quotes', $this->payload)->json('data.id');

    $note = extrasApi()->postJson("/api/v1/sales/quotes/{$quoteId}/delivery-note")->assertCreated();
    expect($note->json('data.items'))->toHaveCount(2)
        ->and($note->json('data.items.0'))->not->toHaveKey('unit_price');

    $orderId = extrasApi()->postJson('/api/v1/sales/orders', array_merge($this->payload, ['status' => 'confirmed']))->json('data.id');
    $withPrices = extrasApi()->postJson("/api/v1/sales/orders/{$orderId}/delivery-notes", ['show_prices' => true])->assertCreated();
    expect($withPrices->json('data.items.0'))->toHaveKey('unit_price');
});

test('a quote can be signed once with a valid PNG signature', function (): void {
    $quoteId = extrasApi()->postJson('/api/v1/sales/quotes', $this->payload)->json('data.id');

    extrasApi()->postJson("/api/v1/sales/quotes/{$quoteId}/signature", ['signed_by' => 'Mario Rossi', 'signature_base64' => 'not-base64!'])
        ->assertUnprocessable()->assertJsonValidationErrors('signature_base64');
    extrasApi()->postJson("/api/v1/sales/quotes/{$quoteId}/signature", ['signed_by' => 'Mario Rossi', 'signature_base64' => base64_encode('plain text')])
        ->assertUnprocessable();

    $signed = extrasApi()->postJson("/api/v1/sales/quotes/{$quoteId}/signature", [
        'signed_by' => 'Mario Rossi', 'signature_base64' => 'data:image/png;base64,'.PNG_1X1,
    ])->assertOk();
    expect($signed->json('data.status'))->toBe('accepted')->and($signed->json('data.signed_by'))->toBe('Mario Rossi')
        ->and($signed->json('data'))->not->toHaveKey('signature_data');

    extrasApi()->postJson("/api/v1/sales/quotes/{$quoteId}/signature", ['signed_by' => 'Mario Rossi', 'signature_base64' => PNG_1X1])
        ->assertUnprocessable();
});

test('deposit invoices are split per tax rate, capped, and deducted from the final invoice', function (): void {
    $orderId = extrasApi()->postJson('/api/v1/sales/orders', array_merge($this->payload, ['status' => 'confirmed']))->json('data.id');

    $deposit = extrasApi()->postJson("/api/v1/sales/orders/{$orderId}/deposit-invoice", ['percentage' => 30])->assertCreated();
    expect($deposit->json('data.is_deposit'))->toBeTrue()
        ->and((float) $deposit->json('data.total_net'))->toEqual(90.0)
        ->and((float) $deposit->json('data.total_tax'))->toEqual(12.6)
        ->and((float) $deposit->json('data.total_gross'))->toEqual(102.6);

    extrasApi()->postJson("/api/v1/sales/orders/{$orderId}/deposit-invoice", ['percentage' => 80])
        ->assertUnprocessable()->assertJsonValidationErrors('percentage');
    extrasApi()->postJson("/api/v1/sales/orders/{$orderId}/deposit-invoice", ['percentage' => 0])->assertUnprocessable();
    extrasApi()->postJson("/api/v1/sales/orders/{$orderId}/deposit-invoice", ['percentage' => 20])->assertCreated();

    $final = extrasApi()->postJson("/api/v1/sales/orders/{$orderId}/invoice")->assertCreated();
    expect($final->json('data.is_deposit'))->toBeFalse()
        ->and((float) $final->json('data.total_net'))->toEqual(150.0)
        ->and((float) $final->json('data.total_tax'))->toEqual(21.0)
        ->and((float) $final->json('data.total_gross'))->toEqual(171.0);

    extrasApi()->postJson("/api/v1/sales/orders/{$orderId}/invoice")->assertUnprocessable();
    extrasApi()->postJson("/api/v1/sales/orders/{$orderId}/deposit-invoice", ['percentage' => 5])->assertUnprocessable();
});

test('the order lines page lists lines across orders and filters the open ones', function (): void {
    $order = extrasApi()->postJson('/api/v1/sales/orders', array_merge($this->payload, ['status' => 'confirmed']))->assertCreated();
    $first = $order->json('data.items.0.id');
    extrasApi()->postJson('/api/v1/sales/orders', array_merge($this->payload, ['status' => 'confirmed']))->assertCreated();

    extrasApi()->postJson('/api/v1/sales/orders/'.$order->json('data.id').'/delivery-notes', ['lines' => [$first => 1]])->assertCreated();

    expect(extrasApi()->getJson('/api/v1/sales/order-lines')->assertOk()->json('meta.total'))->toBe(4);

    $open = extrasApi()->getJson('/api/v1/sales/order-lines?open=1')->assertOk();
    expect($open->json('meta.total'))->toBe(3)
        ->and(collect($open->json('data'))->pluck('id')->contains($first))->toBeFalse()
        ->and($open->json('data.0'))->toHaveKeys(['order_number', 'contact', 'open_quantity']);

    expect(extrasApi()->getJson('/api/v1/sales/order-lines?contact_id='.(string) Str::uuid())->json('meta.total'))->toBe(0);
});
