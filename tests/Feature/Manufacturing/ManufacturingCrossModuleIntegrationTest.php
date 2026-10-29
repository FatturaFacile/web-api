<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Models\TransactionalInbox;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Manufacturing\Contracts\ManufacturingForecastingServiceInterface;
use Modules\Manufacturing\Events\ProductionOrderCreatedEvent;
use Modules\Manufacturing\Listeners\DispatchOutboxOnManufacturingEventListener;
use Modules\Manufacturing\Listeners\ProcurementGoodsReceivedManufacturingListener;
use Modules\Manufacturing\Listeners\WorkflowApprovalManufacturingListener;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgMrpRun;
use Modules\Manufacturing\Models\MfgMrpShortage;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);
});

test('Procurement GoodsReceivedEvent reconciles MRP shortages and records transactional inbox', function () {
    $stockItemId = (string) Str::uuid7();

    $mfgItem = MfgItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'stock_item_id' => $stockItemId,
    ]);

    $mrpRun = MfgMrpRun::factory()->create(['tenant_id' => $this->tenant->id]);

    $shortage = MfgMrpShortage::create([
        'tenant_id' => $this->tenant->id,
        'mrp_run_id' => $mrpRun->id,
        'mfg_item_id' => $mfgItem->id,
        'required_date' => now()->toDateString(),
        'gross_requirement' => 100.0,
        'available_inventory' => 0.0,
        'scheduled_receipts' => 0.0,
        'safety_stock' => 0.0,
        'net_shortage' => 100.0,
        'recommended_action' => 'purchase_order',
    ]);

    $eventId = (string) Str::uuid7();
    $event = new GoodsReceivedEvent(
        receiptId: (string) Str::uuid7(),
        receiptNumber: 'REC-001',
        purchaseOrderId: (string) Str::uuid7(),
        supplierDeliveryNoteRef: 'DDT-1234',
        receivedLines: [
            ['purchase_order_line_id' => (string) Str::uuid7(), 'stock_item_id' => $stockItemId, 'quantity_received' => 60.0, 'quantity_accepted' => 60.0],
        ],
        tenantId: $this->tenant->id,
        companyId: (string) Str::uuid7(),
        eventId: $eventId
    );

    $listener = app(ProcurementGoodsReceivedManufacturingListener::class);
    $listener->handle($event);

    expect(TransactionalInbox::where('message_id', $event->getEventId())->exists())->toBeTrue();

    $shortage->refresh();
    expect((float) $shortage->net_shortage)->toBe(40.0);
});

test('WorkflowInstanceCompletedEvent unlocks and releases locked production orders', function () {
    $order = MfgProductionOrder::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => 'planned',
        'is_locked' => true,
    ]);

    $event = new WorkflowInstanceCompletedEvent(
        instanceId: (string) Str::uuid7(),
        status: 'completed',
        tenantId: $this->tenant->id,
        companyId: (string) Str::uuid7(),
        definitionCode: 'PROD_APPROVAL',
        aggregateType: 'mfg_production_order',
        aggregateId: $order->id
    );

    $listener = app(WorkflowApprovalManufacturingListener::class);
    $listener->handle($event);

    $order->refresh();
    expect($order->is_locked)->toBeFalse()
        ->and($order->status)->toBe('released');
});

test('Manufacturing forecasting service projects multi-month cash, purchase, and inventory inflows', function () {
    $item = MfgItem::factory()->create(['tenant_id' => $this->tenant->id]);
    $mrpRun = MfgMrpRun::factory()->create(['tenant_id' => $this->tenant->id]);

    MfgProductionOrder::factory()->create([
        'tenant_id' => $this->tenant->id,
        'mfg_item_id' => $item->id,
        'standard_cost_total' => 5000.0,
        'planned_end_date' => now()->startOfMonth()->addDays(10)->toDateString(),
    ]);

    MfgMrpShortage::create([
        'tenant_id' => $this->tenant->id,
        'mrp_run_id' => $mrpRun->id,
        'mfg_item_id' => $item->id,
        'required_date' => now()->startOfMonth()->addDays(12)->toDateString(),
        'gross_requirement' => 50.0,
        'net_shortage' => 50.0,
        'recommended_action' => 'purchase_order',
    ]);

    $forecaster = app(ManufacturingForecastingServiceInterface::class);
    $forecast = $forecaster->generateCashAndMaterialForecast($this->tenant->id, 6);

    expect($forecast)->toHaveCount(6)
        ->and($forecast[0]['month'])->toBe(now()->format('Y-m'))
        ->and($forecast[0]['projected_production_cost'])->toBe(5000.0)
        ->and($forecast[0]['projected_material_purchase_cost'])->toBe(750.0);
});

test('Manufacturing domain events are dispatched to transactional outbox', function () {
    $event = new ProductionOrderCreatedEvent(
        productionOrderId: (string) Str::uuid7(),
        orderNumber: 'MO-TEST-OUTBOX',
        mfgItemId: (string) Str::uuid7(),
        targetQuantity: 75.0,
        plannedStartDate: now()->toDateString(),
        plannedEndDate: now()->addDays(5)->toDateString(),
        tenantId: $this->tenant->id
    );

    $listener = app(DispatchOutboxOnManufacturingEventListener::class);
    $listener->handle($event);

    $outboxEntry = TransactionalOutbox::where('aggregate_id', $event->productionOrderId)
        ->where('event_type', 'mfg.production_order.created')
        ->first();

    expect($outboxEntry)->not->toBeNull()
        ->and($outboxEntry->status)->toBe('pending')
        ->and((float) $outboxEntry->payload_json['target_quantity'])->toBe(75.0);
});
