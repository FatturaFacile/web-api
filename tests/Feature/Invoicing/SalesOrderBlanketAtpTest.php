<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Actions\ConvertReplenishmentRequestAction;
use Modules\Invoicing\Contracts\CustomerCreditServiceInterface;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Models\BlanketAgreement;
use Modules\Invoicing\Models\ReplenishmentRequest;
use Modules\Invoicing\Models\SalesOrderAuditLog;
use Modules\Invoicing\Services\AtpCtpCalculationService;
use Modules\Invoicing\Services\BlanketAgreementService;
use Modules\Invoicing\Services\CustomerCreditControlService;
use Modules\Invoicing\Services\SalesOrderFulfillmentService;
use Modules\Manufacturing\Contracts\ProductionPlanningServiceInterface;
use Modules\Manufacturing\DTOs\CreateProductionOrderDTO;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Procurement\Models\PurchaseRequisition;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(['id' => (string) Str::uuid7()], ['name' => 'Blanket Test Tenant', 'legal_name' => 'Blanket Test Tenant Srl']);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->tenantId = (string) $this->tenant->id;

    $this->customer = Contact::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'company_name' => 'Call-Off Client SpA', 'type' => 'client', 'country' => 'IT']);
    $this->product = Product::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'sku' => 'PRD-BLANKET-01', 'name' => 'Pump Unit', 'sale_price' => 100.00, 'cost_price' => 40.00, 'tax_rate' => 22.0, 'track_inventory' => true]);
    $this->warehouse = Warehouse::create(['tenant_id' => $this->tenantId, 'code' => 'MAIN', 'name' => 'Main warehouse', 'is_primary' => true]);

    $this->orders = app(SalesOrderServiceInterface::class);
    $this->fulfillment = app(SalesOrderFulfillmentService::class);
    $this->blanket = app(BlanketAgreementService::class);
    $this->atp = app(AtpCtpCalculationService::class);

    $this->stock = function (float $quantity): void {
        app(StockService::class)->record((string) $this->product->id, 'in', $quantity, null, $this->warehouse);
    };

    $this->order = function (float $quantity, array $extra = []): object {
        return $this->orders->createOrder(['tenant_id' => $this->tenantId, 'contact_id' => (string) $this->customer->id] + $extra, [
            ['product_id' => (string) $this->product->id, 'description' => 'Pump Unit', 'quantity' => $quantity, 'unit_price' => 100.00, 'tax_rate' => 22.0],
        ]);
    };

    $this->agreement = function (string $type = 'quantity_based', array $overrides = []): BlanketAgreement {
        return $this->blanket->create($this->tenantId, $overrides + [
            'contact_id' => (string) $this->customer->id,
            'agreement_type' => $type,
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to' => now()->addMonths(6)->toDateString(),
            'currency_code' => 'EUR',
        ], [
            ['product_id' => (string) $this->product->id, 'description' => 'Pump Unit', 'target_quantity' => 100, 'unit_price' => 10, 'discount_percent' => 0, 'tax_rate' => 22],
        ]);
    };
});

test('ATP is immediate with stock, partial with a shortage and counts confirmed inbound supply', function (): void {
    ($this->stock)(5.0);

    $full = $this->atp->check($this->tenantId, (string) $this->product->id, 3, (string) $this->warehouse->id);
    expect($full['fulfillment_suggestion'])->toBe('full_immediate')->and($full['shortage_qty'])->toBe('0.0000');

    $partial = $this->atp->check($this->tenantId, (string) $this->product->id, 8, (string) $this->warehouse->id);
    expect($partial['available_now_qty'])->toBe('5.0000')
        ->and($partial['shortage_qty'])->toBe('3.0000')
        ->and($partial['atp_status'])->toBe('partial_availability')
        ->and($partial['fulfillment_suggestion'])->toBe('split_partial_and_backorder');

    $supplier = Contact::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'company_name' => 'Pump Supplier Srl', 'type' => 'supplier', 'country' => 'IT']);
    $poId = (string) Str::uuid7();
    DB::table('purchase_orders')->insert(['id' => $poId, 'tenant_id' => $this->tenantId, 'supplier_id' => (string) $supplier->id, 'order_number' => 'PO-ATP-1', 'date' => now()->toDateString(), 'status' => 'sent', 'expected_delivery_date' => now()->addDays(10)->toDateString(), 'shipping_address' => '{}']);
    DB::table('purchase_order_items')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'purchase_order_id' => $poId, 'product_id' => (string) $this->product->id, 'description' => 'Pump Unit', 'quantity' => 10, 'quantity_received' => 0]);

    $inbound = $this->atp->check($this->tenantId, (string) $this->product->id, 8, (string) $this->warehouse->id, now()->addDays(30)->toDateString());
    expect($inbound['earliest_atp_date'])->toBe(now()->addDays(10)->toDateString())->and($inbound['atp_status'])->toBe('available_on_time');

    $late = $this->atp->check($this->tenantId, (string) $this->product->id, 8, (string) $this->warehouse->id, now()->addDays(3)->toDateString());
    expect($late['atp_status'])->toBe('delayed_atp');
});

test('CTP date comes from the lead time of a manufactured product when supply does not cover', function (): void {
    Schema::disableForeignKeyConstraints();
    DB::table('mfg_items')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'item_code' => 'FG-1', 'name' => 'Pump Unit', 'item_type' => 'finished_good', 'product_id' => (string) $this->product->id, 'default_bom_id' => (string) Str::uuid7(), 'lead_time_days' => 7, 'is_active' => true, 'procurement_type' => 'make']);
    Schema::enableForeignKeyConstraints();

    ($this->stock)(1.0);
    $check = $this->atp->check($this->tenantId, (string) $this->product->id, 4, (string) $this->warehouse->id);

    expect($check['atp_status'])->toBe('ctp_required')
        ->and($check['ctp_estimated_date'])->toBe(now()->addDays(7)->toDateString())
        ->and($check['fulfillment_suggestion'])->toBe('split_partial_and_backorder');
});

test('a call-off consumes the contract, exhausts it and cancelling restores the balance', function (): void {
    $agreement = ($this->agreement)();
    $line = $agreement->lines->first();

    expect(fn () => $this->blanket->callOff($agreement, [['blanket_agreement_line_id' => (string) $line->id, 'quantity' => 10]]))->toThrow(ValidationException::class);

    $agreement = $this->blanket->activate($agreement);

    $first = $this->blanket->callOff($agreement, [['blanket_agreement_line_id' => (string) $line->id, 'quantity' => 60]]);
    expect($first->order_type)->toBe('call_off')
        ->and((string) $first->blanket_agreement_id)->toBe((string) $agreement->id)
        ->and((float) $first->items->first()->unit_price)->toBe(10.0);

    expect(fn () => $this->blanket->callOff($agreement, [['blanket_agreement_line_id' => (string) $line->id, 'quantity' => 50]]))->toThrow(ValidationException::class);

    $second = $this->blanket->callOff($agreement, [['blanket_agreement_line_id' => (string) $line->id, 'quantity' => 40]]);
    expect($agreement->fresh()->status)->toBe('exhausted');

    $this->orders->cancelOrder($second, 'customer changed mind', 'sales-rep');

    $fresh = $agreement->fresh();
    expect($fresh->status)->toBe('active')
        ->and((float) $line->fresh()->called_off_quantity)->toBe(60.0)
        ->and((float) $fresh->total_called_off_amount)->toBe(600.0);
});

test('a value based agreement limits the released amount and tolerance allows over-release', function (): void {
    $agreement = ($this->agreement)('value_based', ['total_committed_amount' => 1000, 'over_release_tolerance_percent' => 10]);
    $agreement = $this->blanket->activate($agreement);
    $line = $agreement->lines->first();

    $this->blanket->callOff($agreement, [['blanket_agreement_line_id' => (string) $line->id, 'amount' => 1050]]);
    expect($agreement->fresh()->status)->toBe('exhausted');

    $other = $this->blanket->activate(($this->agreement)('value_based', ['total_committed_amount' => 1000]));
    expect(fn () => $this->blanket->callOff($other, [['blanket_agreement_line_id' => (string) $other->lines->first()->id, 'amount' => 1050]]))->toThrow(ValidationException::class);
});

test('blanket agreements are isolated per tenant and renewal creates a fresh draft', function (): void {
    $agreement = ($this->agreement)();
    $renewed = $this->blanket->renew($agreement);

    expect($renewed->status)->toBe('draft')->and((string) $renewed->renewed_from_id)->toBe((string) $agreement->id)
        ->and($renewed->valid_from->toDateString())->toBe($agreement->valid_to->addDay()->toDateString());

    $other = Tenant::create(['id' => (string) Str::uuid7(), 'name' => 'Other Tenant', 'legal_name' => 'Other Tenant Srl']);
    app(TenantContext::class)->setTenant($other);

    expect(BlanketAgreement::query()->count())->toBe(0);
});

test('an order over the credit limit is blocked, explained, and released by the credit manager', function (): void {
    app(CustomerCreditServiceInterface::class)->updateCreditLimit((string) $this->customer->id, 100.00, 'credit-manager', $this->tenantId);
    ($this->stock)(10.0);

    $order = ($this->order)(5.0, ['warehouse_id' => (string) $this->warehouse->id]);
    $blocked = $this->fulfillment->confirm($order, 'sales-rep');

    expect($blocked->status)->toBe('on_hold')->and($blocked->credit_block_reason)->toBe('credit_limit_exceeded')
        ->and((float) $blocked->items->first()->quantity_reserved)->toBe(0.0);

    $released = app(CustomerCreditControlService::class)->release($blocked, 'credit-manager', 'Approved by CFO');
    expect($released->status)->toBe('confirmed')->and($released->credit_override_notes)->toBe('Approved by CFO');

    $this->fulfillment->allocate($released, 'credit-manager', false);
    expect((float) $released->fresh('items')->items->first()->quantity_reserved)->toBe(5.0);

    $events = SalesOrderAuditLog::query()->where('sales_order_id', (string) $order->id)->pluck('event')->all();
    expect($events)->toContain('credit_blocked', 'credit_released', 'atp_recalculated');
});

test('a partial shipment splits the residual into a backorder line and tracks progress', function (): void {
    ($this->stock)(10.0);
    $order = ($this->order)(10.0, ['warehouse_id' => (string) $this->warehouse->id]);
    $total = (string) $order->total_gross;
    $order = $this->fulfillment->confirm($order, 'sales-rep');
    $item = $order->items->first();

    $this->fulfillment->ship($order, [(string) $item->id => 4], ['carrier_name' => 'BRT'], 'backorder', 'warehouse-user');

    $fresh = $order->fresh('items');
    $backorder = $fresh->items->firstWhere('is_backorder', true);

    expect($fresh->status)->toBe('in_fulfillment')
        ->and((string) $fresh->total_gross)->toBe($total)
        ->and((float) $fresh->items->firstWhere('id', $item->id)->quantity)->toBe(4.0)
        ->and((float) $backorder->quantity)->toBe(6.0)
        ->and((string) $backorder->parent_line_id)->toBe((string) $item->id)
        ->and($backorder->backorder_sequence)->toBe(1)
        ->and((float) $fresh->delivered_percentage)->toBe(40.0);

    $this->fulfillment->ship($fresh, [(string) $backorder->id => 6], [], 'keep_open', 'warehouse-user');

    $done = $order->fresh();
    expect($done->status)->toBe('shipped')->and((float) $done->delivered_percentage)->toBe(100.0);
    expect(SalesOrderAuditLog::query()->where('sales_order_id', (string) $order->id)->where('event', 'backorder_split')->count())->toBe(1);
});

test('cancelling the residual closes the order at the delivered quantity', function (): void {
    ($this->stock)(10.0);
    $order = $this->fulfillment->confirm(($this->order)(10.0, ['warehouse_id' => (string) $this->warehouse->id]), 'sales-rep');
    $item = $order->items->first();

    $this->fulfillment->ship($order, [(string) $item->id => 3], [], 'cancel_residual', 'warehouse-user');

    $fresh = $order->fresh('items');
    expect($fresh->status)->toBe('shipped')->and((float) $fresh->items->first()->quantity)->toBe(3.0)
        ->and((float) $fresh->total_net)->toBe(300.0);
});

test('a shortage on confirmation is pegged to a purchase requisition request, or a production order for made products', function (): void {
    ($this->stock)(2.0);
    $order = $this->fulfillment->confirm(($this->order)(5.0, ['warehouse_id' => (string) $this->warehouse->id]), 'sales-rep');
    $item = $order->items->first()->fresh();

    $request = ReplenishmentRequest::query()->where('sales_order_item_id', (string) $item->id)->firstOrFail();
    expect($request->request_type)->toBe('purchase_requisition')
        ->and((float) $request->quantity)->toBe(3.0)
        ->and($item->replenishment_triggered)->toBeTrue()
        ->and((string) $item->replenishment_reference_id)->toBe((string) $request->id);

    Schema::disableForeignKeyConstraints();
    DB::table('mfg_items')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'item_code' => 'FG-2', 'name' => 'Pump Unit', 'item_type' => 'finished_good', 'product_id' => (string) $this->product->id, 'is_active' => true, 'procurement_type' => 'make']);
    Schema::enableForeignKeyConstraints();

    $second = $this->fulfillment->confirm(($this->order)(5.0, ['warehouse_id' => (string) $this->warehouse->id]), 'sales-rep');
    $made = ReplenishmentRequest::query()->where('sales_order_item_id', (string) $second->items->first()->id)->firstOrFail();
    expect($made->request_type)->toBe('production_order');
});

test('a shortage on confirmation creates one draft purchase requisition, linked to the request, and a second run creates none', function (): void {
    $user = User::create(['name' => 'Planner', 'email' => 'planner@replenish.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);

    ($this->stock)(2.0);
    $order = $this->fulfillment->confirm(($this->order)(5.0, ['warehouse_id' => (string) $this->warehouse->id]), (string) $user->id);
    $request = ReplenishmentRequest::query()->where('sales_order_item_id', (string) $order->items->first()->id)->firstOrFail();

    expect($request->status)->toBe('converted')
        ->and($request->target_type)->toBe('purchase_requisition');

    $requisition = PurchaseRequisition::query()->findOrFail($request->target_id);
    expect($requisition->tenant_id)->toBe($this->tenantId)
        ->and($requisition->status)->toBe('draft')
        ->and((float) $requisition->lines->first()->quantity_requested)->toBe(3.0);

    $again = app(ConvertReplenishmentRequestAction::class)->execute((string) $request->id, $this->tenantId);

    expect($again)->toBeNull()
        ->and(PurchaseRequisition::query()->count())->toBe(1);
});

test('a request of another tenant is never converted', function (): void {
    $user = User::create(['name' => 'Planner', 'email' => 'planner2@replenish.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    ($this->stock)(0.0);
    $order = $this->fulfillment->confirm(($this->order)(2.0, ['warehouse_id' => (string) $this->warehouse->id]), (string) $user->id);
    $request = ReplenishmentRequest::query()->where('sales_order_item_id', (string) $order->items->first()->id)->firstOrFail();
    $request->forceFill(['status' => 'open', 'target_type' => null, 'target_id' => null])->save();

    expect(app(ConvertReplenishmentRequestAction::class)->execute((string) $request->id, (string) Str::uuid7()))->toBeNull()
        ->and($request->fresh()->status)->toBe('open');
});

test('a shortage of a made product becomes a production order, or stays open when it cannot be planned', function (): void {
    Schema::disableForeignKeyConstraints();
    DB::table('mfg_items')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'item_code' => 'FG-3', 'name' => 'Pump Unit', 'item_type' => 'finished_good', 'product_id' => (string) $this->product->id, 'is_active' => true, 'procurement_type' => 'make']);
    Schema::enableForeignKeyConstraints();

    // No released BOM: the planner refuses and the request stays open for the planners.
    $order = $this->fulfillment->confirm(($this->order)(4.0, ['warehouse_id' => (string) $this->warehouse->id]), 'sales-rep');
    $request = ReplenishmentRequest::query()->where('sales_order_item_id', (string) $order->items->first()->id)->firstOrFail();
    expect($request->request_type)->toBe('production_order')
        ->and($request->status)->toBe('open')
        ->and($request->target_id)->toBeNull();

    $productionOrderId = (string) Str::uuid7();
    $planner = Mockery::mock(ProductionPlanningServiceInterface::class);
    $planner->shouldReceive('planOrder')->once()->withArgs(fn (CreateProductionOrderDTO $dto): bool => $dto->tenantId === $this->tenantId
        && $dto->targetQuantity === '4.0000' && $dto->salesOrderId === (string) $order->id)
        ->andReturn(new MfgProductionOrder(['id' => $productionOrderId]));
    app()->instance(ProductionPlanningServiceInterface::class, $planner);

    $document = app(ConvertReplenishmentRequestAction::class)->execute((string) $request->id, $this->tenantId);
    $request->refresh();

    expect($document)->not->toBeNull()
        ->and($request->status)->toBe('converted')
        ->and($request->target_type)->toBe('production_order')
        ->and($request->target_id)->toBe($productionOrderId);
});
