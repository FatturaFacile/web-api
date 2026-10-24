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
    $this->tenant = Tenant::create([
        'name' => 'FatturaFacile Italy SRL',
        'legal_name' => 'FatturaFacile Italy SRL',
        'vat_number' => '00811720580',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    $user = User::create([
        'name' => 'Leonardo',
        'email' => 'leo@fatturafacile.com',
        'password' => bcrypt('secret123'),
        'locale' => 'it',
    ]);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);

    $this->contact = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Milan Customer SpA',
        'country' => 'IT',
    ]);

    $this->payload = [
        'contact_id' => $this->contact->id,
        'date' => '2026-03-10',
        'currency' => 'EUR',
        'items' => [
            ['description' => 'Widget', 'quantity' => 10, 'unit_price' => 100, 'tax_rate' => 22],
            ['description' => 'Service', 'quantity' => 2, 'unit_price' => 50, 'tax_rate' => 22, 'discount_percentage' => 10],
        ],
    ];
});

function api()
{
    return test()->withHeader('X-Tenant-ID', test()->tenant->id);
}

test('quote is created with computed totals and gapless numbering', function (): void {
    $first = api()->postJson('/api/v1/sales/quotes', $this->payload)->assertCreated();
    $second = api()->postJson('/api/v1/sales/quotes', $this->payload)->assertCreated();

    expect($first->json('data.quote_number'))->toBe('QT-2026-0001')
        ->and($second->json('data.quote_number'))->toBe('QT-2026-0002')
        ->and((float) $first->json('data.total_net'))->toBe(1090.0)
        ->and((float) $first->json('data.total_tax'))->toBe(239.8)
        ->and((float) $first->json('data.total_gross'))->toBe(1329.8);
});

test('quote validation rejects empty items', function (): void {
    api()->postJson('/api/v1/sales/quotes', array_merge($this->payload, ['items' => []]))
        ->assertUnprocessable();
});

test('quote converts to an order only once', function (): void {
    $quoteId = api()->postJson('/api/v1/sales/quotes', $this->payload)->json('data.id');

    $order = api()->postJson("/api/v1/sales/quotes/{$quoteId}/convert-to-order")->assertCreated();
    expect($order->json('data.status'))->toBe('confirmed')
        ->and($order->json('data.quote_id'))->toBe($quoteId)
        ->and($order->json('data.items'))->toHaveCount(2);

    api()->postJson("/api/v1/sales/quotes/{$quoteId}/convert-to-order")->assertUnprocessable();
});

test('order supports partial delivery notes then invoicing', function (): void {
    $order = api()->postJson('/api/v1/sales/orders', array_merge($this->payload, ['status' => 'confirmed']))->assertCreated();
    $orderId = $order->json('data.id');
    $widgetLine = $order->json('data.items.0.id');

    $note = api()->postJson("/api/v1/sales/orders/{$orderId}/delivery-notes", ['lines' => [$widgetLine => 4]])->assertCreated();
    expect($note->json('data.items'))->toHaveCount(1);

    api()->postJson("/api/v1/sales/orders/{$orderId}/delivery-notes", ['lines' => [$widgetLine => 7]])->assertUnprocessable();

    $rest = api()->postJson("/api/v1/sales/orders/{$orderId}/delivery-notes")->assertCreated();
    expect((float) $rest->json('data.items.0.quantity'))->toBe(6.0);
    expect(api()->getJson("/api/v1/sales/orders/{$orderId}")->json('data.status'))->toBe('shipped');

    $invoice = api()->postJson('/api/v1/sales/delivery-notes/'.$note->json('data.id').'/invoice')->assertCreated();
    expect($invoice->json('data.invoice_number'))->toStartWith('INV-2026-');
    api()->postJson('/api/v1/sales/delivery-notes/'.$note->json('data.id').'/invoice')->assertUnprocessable();
});

test('order can be invoiced once', function (): void {
    $orderId = api()->postJson('/api/v1/sales/orders', $this->payload)->json('data.id');

    api()->postJson("/api/v1/sales/orders/{$orderId}/invoice")->assertCreated();
    api()->postJson("/api/v1/sales/orders/{$orderId}/invoice")->assertUnprocessable();
});

test('payments update paid total and status, and reject overpayment', function (): void {
    $orderId = api()->postJson('/api/v1/sales/orders', $this->payload)->json('data.id');
    $invoiceId = api()->postJson("/api/v1/sales/orders/{$orderId}/invoice")->json('data.id');

    api()->postJson("/api/v1/sales/invoices/{$invoiceId}/payments", [
        'amount' => 500, 'paid_at' => '2026-03-20', 'payment_method' => 'MP05',
    ])->assertCreated();
    expect(Invoice::find($invoiceId)->payment_status)->toBe('partially_paid');

    api()->postJson("/api/v1/sales/invoices/{$invoiceId}/payments", ['amount' => 5000, 'paid_at' => '2026-03-21'])
        ->assertUnprocessable();
    api()->postJson("/api/v1/sales/invoices/{$invoiceId}/payments", ['amount' => 1, 'paid_at' => '2026-03-21', 'payment_method' => 'XX'])
        ->assertUnprocessable();

    api()->postJson("/api/v1/sales/invoices/{$invoiceId}/payments", ['amount' => 829.8, 'paid_at' => '2026-03-22'])->assertCreated();
    $invoice = Invoice::find($invoiceId);
    expect($invoice->payment_status)->toBe('paid')
        ->and((float) $invoice->total_paid)->toBe(1329.8);

    api()->getJson("/api/v1/sales/invoices/{$invoiceId}/payments")->assertOk()->assertJsonCount(2, 'data');
});

test('proforma converts to a standard invoice linked back', function (): void {
    $proforma = Invoice::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->contact->id,
        'invoice_type' => 'proforma',
        'invoice_number' => 'PF-1',
        'date' => '2026-03-01',
        'currency' => 'EUR',
        'total_gross' => 122,
        'jurisdiction' => 'IT',
    ]);
    $proforma->items()->create(['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22, 'line_total' => 100]);

    $response = api()->postJson("/api/v1/sales/invoices/{$proforma->id}/convert-proforma")->assertCreated();
    expect($response->json('data.proforma_id'))->toBe($proforma->id);

    api()->postJson("/api/v1/sales/invoices/{$proforma->id}/convert-proforma")->assertUnprocessable();
    api()->postJson("/api/v1/sales/invoices/{$proforma->id}/payments", ['amount' => 1, 'paid_at' => '2026-03-21'])->assertUnprocessable();
});
