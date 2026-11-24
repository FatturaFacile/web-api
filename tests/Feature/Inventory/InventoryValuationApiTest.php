<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Inventory\Models\InventoryRevaluation;
use Modules\Inventory\Models\StockItemCategory;
use Tests\Support\InventoryValuationFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->user] = Fx::actingTenantUser();
    $this->tenantId = (string) $this->tenant->id;
    ['warehouse' => $this->warehouse, 'bin' => $this->bin] = Fx::warehouseWithBin($this->tenantId);
});

/**
 * Draft landed cost document on a fresh receipt of the current tenant.
 */
function draftLandedCostId(object $test): string
{
    $receipt = Fx::purchaseReceipt($test->tenantId);
    Fx::receive(Fx::item($test->tenantId, 'fifo'), $test->bin, 10, 5.00, (string) $receipt->id);

    return (string) $test->postJson('/api/v1/inventory/landed-costs', [
        'document_date' => now()->toDateString(),
        'cost_type' => 'customs_duty',
        'allocation_key' => 'quantity',
        'currency' => 'EUR',
        'total_amount' => 12.34,
        'receipt_ids' => [(string) $receipt->id],
    ])->assertCreated()->json('id');
}

test('valuation config reports the item override as effective method (AC1)', function (): void {
    $item = Fx::item($this->tenantId, 'fifo', ['valuation_method' => 'weighted_average']);
    $inherited = Fx::item($this->tenantId, 'standard_cost');

    $this->getJson('/api/v1/inventory/valuation/config?item_id='.$item->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.item_id', (string) $item->id)
        ->assertJsonPath('data.0.sku', $item->sku)
        ->assertJsonPath('data.0.category_method', 'fifo')
        ->assertJsonPath('data.0.item_method', 'weighted_average')
        ->assertJsonPath('data.0.effective_method', 'weighted_average')
        ->assertJsonPath('data.0.source', 'item');

    $this->getJson('/api/v1/inventory/valuation/config?category_id='.$inherited->category_id)
        ->assertOk()
        ->assertJsonPath('data.0.effective_method', 'standard_cost')
        ->assertJsonPath('data.0.source', 'category');
});

test('updating a category valuation method and GL accounts', function (): void {
    $item = Fx::item($this->tenantId, 'fifo');
    $ppv = ChartOfAccount::create(['tenant_id' => $this->tenantId, 'account_code' => '5111', 'name' => 'PPV raw materials', 'account_class' => 'expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_active' => true]);

    $this->putJson('/api/v1/inventory/valuation/categories/'.$item->category_id, [
        'valuation_method' => 'moving_average',
        'ppv_account_id' => $ppv->id,
    ])->assertOk()
        ->assertJsonPath('valuation_method', 'weighted_average')
        ->assertJsonPath('ppv_account_id', (string) $ppv->id);

    expect(StockItemCategory::query()->findOrFail($item->category_id)->valuation_method)->toBe('weighted_average');

    $this->putJson('/api/v1/inventory/valuation/categories/'.$item->category_id, ['valuation_method' => 'lifo'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['valuation_method']);

    $this->putJson('/api/v1/inventory/valuation/categories/'.$item->category_id, [
        'valuation_method' => 'fifo',
        'inventory_account_id' => (string) Str::uuid7(),
    ])->assertStatus(422)->assertJsonValidationErrors(['inventory_account_id']);
});

test('changing the item method with stock on hand revalues inventory and posts the adjustment', function (): void {
    Fx::fiscalYear($this->tenantId, (int) now()->format('Y'));
    $item = Fx::item($this->tenantId, 'fifo');
    Fx::receive($item, $this->bin, 10, 5.00);
    Fx::receive($item, $this->bin, 10, 7.00);
    Fx::issue($item, $this->bin, 5);
    // FIFO value: 5 * 5 + 10 * 7 = 95.00; WAC value: 15 * 6.00 = 90.00

    $response = $this->putJson('/api/v1/inventory/valuation/items/'.$item->id, [
        'valuation_method' => 'weighted_average',
        'volume_m3' => 0.25,
    ]);

    $response->assertOk()
        ->assertJsonPath('valuation_method', 'weighted_average')
        ->assertJsonPath('effective_method', 'weighted_average')
        ->assertJsonPath('source', 'item')
        ->assertJsonPath('volume_m3', '0.2500')
        ->assertJsonPath('revaluation.total_inventory_delta', '-5.00')
        ->assertJsonPath('revaluation.total_cogs_delta', '5.00');

    $journal = JournalEntry::query()->findOrFail($response->json('revaluation.journal_entry_id'));
    expect($journal->source_document_type)->toBe('inventory_revaluation')
        ->and($journal->source_document_id)->toBe($response->json('revaluation.id'))
        ->and($journal->isBalanced())->toBeTrue()
        ->and((float) $journal->total_amount)->toBe(5.0);

    // Reverting to the category default (null) revalues back.
    $this->putJson('/api/v1/inventory/valuation/items/'.$item->id, ['valuation_method' => null])
        ->assertOk()
        ->assertJsonPath('valuation_method', null)
        ->assertJsonPath('effective_method', 'fifo')
        ->assertJsonPath('source', 'category')
        ->assertJsonPath('revaluation.total_inventory_delta', '5.00');
});

test('changing the item method without stock or with revalue_on_change=false creates no revaluation', function (): void {
    $empty = Fx::item($this->tenantId, 'fifo');
    $this->putJson('/api/v1/inventory/valuation/items/'.$empty->id, ['valuation_method' => 'standard_cost', 'standard_cost' => 3])
        ->assertOk()
        ->assertJsonPath('revaluation', null)
        ->assertJsonPath('standard_cost', '3.0000');

    $stocked = Fx::item($this->tenantId, 'fifo');
    Fx::receive($stocked, $this->bin, 2, 5.00);
    $this->putJson('/api/v1/inventory/valuation/items/'.$stocked->id, ['valuation_method' => 'standard_cost', 'standard_cost' => 4, 'revalue_on_change' => false])
        ->assertOk()
        ->assertJsonPath('revaluation', null);

    expect(InventoryRevaluation::count())->toBe(0);
});

test('changing the item method is rejected with 422 when the fiscal period is closed', function (): void {
    Fx::fiscalYear($this->tenantId, (int) now()->format('Y'), 'open', 'closed');
    $item = Fx::item($this->tenantId, 'fifo');
    Fx::receive($item, $this->bin, 10, 5.00);

    $this->putJson('/api/v1/inventory/valuation/items/'.$item->id, ['valuation_method' => 'standard_cost', 'standard_cost' => 6])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['valuation_method']);

    expect($item->refresh()->valuation_method)->toBeNull();
});

test('cost layers endpoint lists layers with effective unit cost and filters open layers', function (): void {
    $item = Fx::item($this->tenantId, 'fifo');
    Fx::receive($item, $this->bin, 10, 5.00);
    Fx::receive($item, $this->bin, 10, 7.00);
    Fx::issue($item, $this->bin, 10);

    $this->getJson('/api/v1/inventory/valuation/cost-layers?item_id='.$item->id)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'item_id', 'lot_id', 'received_at', 'original_quantity', 'remaining_quantity', 'unit_cost', 'landed_cost_unit', 'effective_unit_cost', 'receipt_id']], 'links', 'meta']);

    $this->getJson('/api/v1/inventory/valuation/cost-layers?item_id='.$item->id.'&open_only=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.remaining_quantity', '10.0000')
        ->assertJsonPath('data.0.unit_cost', '7.0000')
        ->assertJsonPath('data.0.effective_unit_cost', '7.0000');
});

test('valuation summary reports the method per item and accepts legacy method values', function (): void {
    $item = Fx::item($this->tenantId, 'fifo');
    Fx::receive($item, $this->bin, 2, 5.00);

    $this->getJson('/api/v1/inventory/valuation/summary')
        ->assertOk()
        ->assertJsonPath('valuation_method', 'effective')
        ->assertJsonPath('items.0.method', 'fifo');

    $this->getJson('/api/v1/inventory/valuation/summary?valuation_method=AVCO')
        ->assertOk()
        ->assertJsonPath('items.0.method', 'weighted_average');
});

test('valuation management requires the inventory.valuation.manage permission', function (): void {
    $documentId = draftLandedCostId($this);
    $item = Fx::item($this->tenantId, 'fifo');

    $member = Fx::memberOf($this->tenant, 'warehouse_clerk', ['inventory.movements.*']);
    Fx::activate($this->tenant, $member);

    $this->getJson('/api/v1/inventory/landed-costs/'.$documentId)->assertOk();
    $this->getJson('/api/v1/inventory/valuation/config')->assertOk();
    $this->postJson("/api/v1/inventory/landed-costs/{$documentId}/apply")->assertForbidden();
    $this->postJson("/api/v1/inventory/landed-costs/{$documentId}/reverse", ['reason' => 'nope'])->assertForbidden();
    $this->putJson('/api/v1/inventory/valuation/items/'.$item->id, ['valuation_method' => 'fifo'])->assertForbidden();
    $this->putJson('/api/v1/inventory/valuation/categories/'.$item->category_id, ['valuation_method' => 'fifo'])->assertForbidden();

    $manager = Fx::memberOf($this->tenant, 'controller', ['inventory.valuation.manage']);
    Fx::activate($this->tenant, $manager);
    $this->putJson('/api/v1/inventory/valuation/items/'.$item->id, ['valuation_method' => 'fifo'])->assertOk();
});

test('segregation of duties prevents the creator from applying when configured', function (): void {
    config(['inventory.valuation.segregation_of_duties' => true]);
    Fx::fiscalYear($this->tenantId, (int) now()->format('Y'));
    $documentId = draftLandedCostId($this);

    $this->postJson("/api/v1/inventory/landed-costs/{$documentId}/apply")->assertForbidden();

    $approver = Fx::memberOf($this->tenant, 'admin');
    Fx::activate($this->tenant, $approver);
    $this->postJson("/api/v1/inventory/landed-costs/{$documentId}/apply")->assertOk()->assertJsonPath('status', 'applied');
});

test('users of another tenant cannot see or act on landed cost documents', function (): void {
    $documentId = draftLandedCostId($this);
    $item = Fx::item($this->tenantId, 'fifo');

    $otherTenant = Tenant::create(['name' => 'Other '.Str::random(5), 'legal_name' => 'Other SpA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $intruder = Fx::memberOf($otherTenant, 'owner');
    Fx::activate($otherTenant, $intruder);

    $this->getJson('/api/v1/inventory/landed-costs/'.$documentId)->assertNotFound();
    $this->postJson("/api/v1/inventory/landed-costs/{$documentId}/apply")->assertNotFound();
    $this->postJson("/api/v1/inventory/landed-costs/{$documentId}/reverse", ['reason' => 'intrusion'])->assertNotFound();
    $this->putJson('/api/v1/inventory/valuation/items/'.$item->id, ['valuation_method' => 'fifo'])->assertNotFound();
    $this->getJson('/api/v1/inventory/landed-costs')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/inventory/valuation/cost-layers?item_id='.$item->id)->assertOk()->assertJsonCount(0, 'data');
});

test('users without membership in the active tenant are forbidden', function (): void {
    $outsider = Fx::memberOf(Tenant::create(['name' => 'Elsewhere '.Str::random(5), 'legal_name' => 'Elsewhere SpA', 'country' => 'IT', 'default_currency' => 'EUR']), 'owner');
    Fx::activate($this->tenant, $outsider);

    $this->getJson('/api/v1/inventory/valuation/config')->assertForbidden();
    $this->getJson('/api/v1/inventory/landed-costs')->assertForbidden();
    $this->getJson('/api/v1/inventory/book/exports')->assertForbidden();
});

test('unauthenticated requests to the new endpoints get 401', function (): void {
    $this->app['auth']->forgetGuards();

    foreach ([
        ['GET', '/api/v1/inventory/valuation/config'],
        ['GET', '/api/v1/inventory/valuation/cost-layers'],
        ['PUT', '/api/v1/inventory/valuation/items/'.Str::uuid7()],
        ['PUT', '/api/v1/inventory/valuation/categories/'.Str::uuid7()],
        ['GET', '/api/v1/inventory/landed-costs'],
        ['POST', '/api/v1/inventory/landed-costs'],
        ['POST', '/api/v1/inventory/landed-costs/preview'],
        ['GET', '/api/v1/inventory/landed-costs/'.Str::uuid7()],
        ['POST', '/api/v1/inventory/landed-costs/'.Str::uuid7().'/apply'],
        ['POST', '/api/v1/inventory/landed-costs/'.Str::uuid7().'/reverse'],
        ['GET', '/api/v1/inventory/book?year=2026'],
        ['GET', '/api/v1/inventory/book/exports'],
        ['POST', '/api/v1/inventory/book/exports'],
        ['GET', '/api/v1/inventory/book/exports/'.Str::uuid7().'/download'],
    ] as [$method, $uri]) {
        $this->json($method, $uri)->assertUnauthorized();
    }
});
