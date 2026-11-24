<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Invoicing\Contracts\CustomerCreditServiceInterface;
use Modules\Invoicing\Services\BlanketAgreementService;

require_once __DIR__.'/InvoicingBillingSupport.php';

/*
 * HTTP coverage of the sales orders workspace and blanket agreement API (GH-137): draft CRUD, ATP check, confirmation
 * with stock allocation, credit block and the permission-protected release, partial DDT with backorder, call-off
 * contracts and tenant isolation.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    billingSetUp($this);
    $this->product = billingInTenant($this->tenant, fn (): Product => Product::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'sku' => 'PRD-API-01', 'name' => 'Valve', 'sale_price' => 100, 'cost_price' => 40, 'tax_rate' => 22, 'track_inventory' => true]));
    $this->warehouse = billingInTenant($this->tenant, fn (): Warehouse => Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main', 'is_primary' => true]));
    billingInTenant($this->tenant, fn () => app(StockService::class)->record((string) $this->product->id, 'in', 10.0, null, $this->warehouse));

    $this->payload = fn (float $quantity = 10): array => [
        'contact_id' => (string) $this->customer->id,
        'warehouse_id' => (string) $this->warehouse->id,
        'items' => [['product_id' => (string) $this->product->id, 'description' => 'Valve', 'quantity' => $quantity, 'unit_price' => 100, 'tax_rate' => 22]],
    ];
});

test('a draft order is created, rewritten, checked for ATP, confirmed with allocation and deleted only while draft', function (): void {
    $atp = $this->postJson('/api/v1/sales-orders/atp-check', ['lines' => [['product_id' => (string) $this->product->id, 'quantity' => 4], ['product_id' => (string) $this->product->id, 'quantity' => 25]]])
        ->assertOk()->json('data');
    expect($atp[0]['fulfillment_suggestion'])->toBe('full_immediate')->and($atp[1]['shortage_qty'])->toBe('15.0000');

    $id = $this->postJson('/api/v1/sales-orders', ($this->payload)(4))->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.order_type', 'standard')->json('data.id');

    $this->putJson("/api/v1/sales-orders/{$id}", ($this->payload)(6))->assertOk()->assertJsonPath('data.lines.0.ordered_quantity', '6.0000');

    $confirmed = $this->postJson("/api/v1/sales-orders/{$id}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed')->json('data');
    expect($confirmed['lines'][0]['reserved_quantity'])->toBe('6.0000')
        ->and($confirmed['lines'][0]['atp_status'])->toBe('available_on_time')
        ->and($confirmed['allocated_percentage'])->toBe('100.00')
        ->and(collect($confirmed['audit_log'])->pluck('event')->all())->toContain('status_changed', 'atp_recalculated');

    $this->putJson("/api/v1/sales-orders/{$id}", ($this->payload)(2))->assertUnprocessable();
    $this->deleteJson("/api/v1/sales-orders/{$id}")->assertUnprocessable();

    $draft = $this->postJson('/api/v1/sales-orders', ($this->payload)(1))->json('data.id');
    $this->deleteJson("/api/v1/sales-orders/{$draft}")->assertNoContent();
    $this->getJson("/api/v1/sales-orders/{$draft}")->assertNotFound();
});

test('an order over the credit limit is blocked and only a credit manager can release it', function (): void {
    billingInTenant($this->tenant, fn () => app(CustomerCreditServiceInterface::class)->updateCreditLimit((string) $this->customer->id, 100.00, 'seed', (string) $this->tenant->id));

    $id = $this->postJson('/api/v1/sales-orders', ($this->payload)(5))->json('data.id');
    $blocked = $this->postJson("/api/v1/sales-orders/{$id}/confirm")->assertOk()->assertJsonPath('data.status', 'on_hold')->assertJsonPath('data.credit_block_reason', 'credit_limit_exceeded')->json('data');
    expect($blocked['credit_exposure']['over_limit'])->toBeTrue()->and($blocked['credit_exposure']['credit_limit'])->toBe('100.00');

    $this->getJson('/api/v1/sales-orders?credit_blocked=1')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/sales-orders/{$id}/create-ddt")->assertUnprocessable();

    $this->postJson("/api/v1/sales-orders/{$id}/credit-release", ['notes' => 'Approved by finance'])->assertForbidden();

    billingActor($this, 'sales_manager');
    $this->postJson("/api/v1/sales-orders/{$id}/credit-release", ['notes' => 'x'])->assertUnprocessable();
    $this->postJson("/api/v1/sales-orders/{$id}/credit-release", ['notes' => 'Approved by finance'])->assertOk()
        ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.credit_override_notes', 'Approved by finance')->assertJsonPath('data.lines.0.reserved_quantity', '5.0000');
});

test('a partial DDT splits a backorder line that the backorder monitor lists', function (): void {
    $id = $this->postJson('/api/v1/sales-orders', ($this->payload)(10))->json('data.id');
    $line = $this->postJson("/api/v1/sales-orders/{$id}/confirm")->json('data.lines.0.id');

    $this->postJson("/api/v1/sales-orders/{$id}/create-ddt", ['lines' => [$line => 4], 'residual_mode' => 'backorder'])->assertCreated();

    $order = $this->getJson("/api/v1/sales-orders/{$id}")->assertOk()->assertJsonPath('data.status', 'in_fulfillment')->assertJsonPath('data.has_backorders', true)->assertJsonPath('data.delivered_percentage', '40.00')->json('data');
    $backorder = collect($order['lines'])->firstWhere('is_backorder', true);
    expect($backorder['ordered_quantity'])->toBe('6.0000')->and($backorder['parent_line_id'])->toBe($line);

    $this->getJson('/api/v1/sales-orders?has_backorders=1')->assertOk()->assertJsonCount(1, 'data');
    $monitor = $this->getJson('/api/v1/sales-orders/backorders')->assertOk()->json('data');
    expect($monitor)->toHaveCount(1)->and($monitor[0]['open_quantity'])->toBe('6.0000');

    $this->postJson("/api/v1/sales-orders/{$id}/create-ddt", ['lines' => [$line => 1]])->assertUnprocessable();
});

test('a blanket agreement is created, activated and called off over HTTP within its balance', function (): void {
    $agreementId = $this->postJson('/api/v1/blanket-agreements', [
        'contact_id' => (string) $this->customer->id,
        'agreement_type' => 'quantity_based',
        'valid_from' => now()->subDay()->toDateString(),
        'valid_to' => now()->addMonths(3)->toDateString(),
        'lines' => [['product_id' => (string) $this->product->id, 'description' => 'Valve', 'target_quantity' => 20, 'unit_price' => 80, 'tax_rate' => 22]],
    ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

    $lineId = $this->getJson("/api/v1/blanket-agreements/{$agreementId}")->assertOk()->json('data.lines.0.id');

    $this->postJson("/api/v1/blanket-agreements/{$agreementId}/call-off", ['lines' => [['blanket_agreement_line_id' => $lineId, 'quantity' => 5]]])->assertUnprocessable();
    $this->postJson("/api/v1/blanket-agreements/{$agreementId}/activate")->assertOk()->assertJsonPath('data.status', 'active');

    $order = $this->postJson("/api/v1/blanket-agreements/{$agreementId}/call-off", ['lines' => [['blanket_agreement_line_id' => $lineId, 'quantity' => 15]]])
        ->assertCreated()->assertJsonPath('data.order_type', 'call_off')->assertJsonPath('data.lines.0.unit_price', '80.0000')->json('data');

    $this->postJson("/api/v1/blanket-agreements/{$agreementId}/call-off", ['lines' => [['blanket_agreement_line_id' => $lineId, 'quantity' => 6]]])->assertUnprocessable();

    $this->getJson("/api/v1/blanket-agreements/{$agreementId}")->assertJsonPath('data.lines.0.called_off_quantity', '15.0000')->assertJsonPath('data.called_off_percentage', '75.00');

    $this->postJson("/api/v1/sales-orders/{$order['id']}/cancel", ['reason' => 'not needed'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->getJson("/api/v1/blanket-agreements/{$agreementId}")->assertJsonPath('data.lines.0.called_off_quantity', '0.0000');

    $this->getJson('/api/v1/blanket-agreements?status=active')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/blanket-agreements/{$agreementId}/close")->assertOk()->assertJsonPath('data.status', 'closed');
});

test('orders and agreements of another company are not reachable', function (): void {
    $foreignAgreement = billingInTenant($this->otherTenant, fn () => app(BlanketAgreementService::class)->create((string) $this->otherTenant->id, [
        'contact_id' => (string) $this->foreignCustomer->id, 'agreement_type' => 'quantity_based', 'valid_from' => now()->toDateString(), 'valid_to' => now()->addMonth()->toDateString(),
    ], [['description' => 'X', 'target_quantity' => 1, 'unit_price' => 1, 'tax_rate' => 0]]));
    $foreignOrder = billingOrder($this->otherTenant, $this->foreignCustomer);

    $this->getJson("/api/v1/blanket-agreements/{$foreignAgreement->id}")->assertNotFound();
    $this->getJson("/api/v1/sales-orders/{$foreignOrder->id}")->assertNotFound();
    $this->postJson('/api/v1/sales-orders', ['contact_id' => (string) $this->foreignCustomer->id] + ($this->payload)())->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);
});
