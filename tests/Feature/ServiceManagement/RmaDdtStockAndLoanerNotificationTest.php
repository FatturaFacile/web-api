<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Logistics\Services\WarehouseStockService;
use Modules\ServiceManagement\DTOs\ReturnRmaDTO;
use Modules\ServiceManagement\Events\LoanerOverdueEvent;
use Modules\ServiceManagement\Events\RmaCompletedEvent;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Models\RmaLine;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Services\RmaDeliveryNoteService;
use Modules\ServiceManagement\Services\RmaService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->user] = tfrTenant('Rma Ddt Tenant');
    app(TenantContext::class)->setTenant($this->tenant);
    $this->customer = tfrCustomer($this->tenant->id, 'Cliente RMA');
    $this->product = tfrProduct($this->tenant->id, 'PSU-1', 10, 30);
    $this->warehouse = tfrWarehouse($this->tenant->id, 'MAIN');
    $this->passport = SerializedEquipmentPassport::factory()->create([
        'tenant_id' => $this->tenant->id,
        'current_owner_customer_id' => $this->customer->id,
        'product_id' => $this->product->id,
    ]);
    $this->rma = fn (array $attributes = []): DepotRepairRmaOrder => DepotRepairRmaOrder::factory()->create($attributes + [
        'tenant_id' => $this->tenant->id,
        'equipment_passport_id' => $this->passport->id,
        'customer_id' => $this->customer->id,
        'status' => 'ready_for_return',
    ]);
});

test('returning a repaired unit issues a real repair DDT that does not transfer ownership', function (): void {
    $rma = ($this->rma)();

    $returned = app(RmaService::class)->returnToCustomer($rma, new ReturnRmaDTO(vatExemptArt15Dpr633: true));

    $ddt = SalesDeliveryNote::query()->findOrFail($returned->return_delivery_note_id);
    expect($ddt->type)->toBe('repair_return')
        ->and($ddt->status)->toBe('issued')
        ->and($ddt->transport_reason_code)->toBe('repair')
        ->and($ddt->contact_id)->toBe($this->customer->id)
        ->and($ddt->notes)->toContain('art. 15 DPR 633/72')
        ->and($ddt->notes)->toContain($rma->rma_number)
        ->and($ddt->items)->toHaveCount(1);
});

test('the repair DDT carries no Art. 15 note when the RMA is not flagged, and is created once', function (): void {
    $rma = ($this->rma)();
    $service = app(RmaService::class);

    $returned = $service->returnToCustomer($rma, new ReturnRmaDTO);
    $first = $returned->return_delivery_note_id;

    expect(SalesDeliveryNote::query()->findOrFail($first)->notes)->not->toContain('art. 15')
        ->and(SalesDeliveryNote::query()->where('type', 'repair_return')->count())->toBe(1);

    $again = app(RmaDeliveryNoteService::class)->issueReturnNote($returned->refresh());
    expect((string) $again->id)->toBe($first)->and(SalesDeliveryNote::query()->where('type', 'repair_return')->count())->toBe(1);
});

test('completing a repair takes the consumed spare parts out of stock, once', function (): void {
    app(WarehouseStockService::class)->receiveOpeningStock($this->warehouse->id, $this->product->id, 10);
    $rma = ($this->rma)();
    $line = RmaLine::query()->create([
        'tenant_id' => $this->tenant->id, 'rma_order_id' => $rma->id, 'line_type' => RmaLine::TYPE_PART,
        'product_id' => $this->product->id, 'description' => 'PSU', 'qty' => 3, 'unit_cost' => 5, 'unit_price' => 8,
    ]);

    $event = new RmaCompletedEvent(
        rmaOrderId: (string) $rma->id, rmaNumber: $rma->rma_number, repairOutcome: 'repaired', warrantyStatus: 'covered_in_warranty',
        consumedParts: [['rma_line_id' => (string) $line->id, 'product_id' => (string) $this->product->id, 'description' => 'PSU', 'qty' => 3.0, 'unit_cost' => 5.0, 'charged_to' => 'customer', 'replacement_part_serial' => null]],
        tenantId: (string) $this->tenant->id, companyId: '',
    );

    Event::dispatch($event);
    expect(app(WarehouseStockService::class)->onHand($this->warehouse->id, $this->product->id))->toBe(7.0);

    // a replayed event never books the same line twice
    Event::dispatch($event);
    expect(app(WarehouseStockService::class)->onHand($this->warehouse->id, $this->product->id))->toBe(7.0);
});

test('an overdue loaner is notified to the tenant administrators through the dispatcher', function (): void {
    Event::dispatch(new LoanerOverdueEvent(
        loanerId: (string) Str::uuid(), rmaOrderId: (string) Str::uuid(), loanerSerialNumber: 'LN-77', customerId: (string) $this->customer->id,
        daysOutstanding: 9, tenantId: (string) $this->tenant->id, companyId: '',
    ));

    $notification = $this->user->notifications()->firstOrFail();
    expect($notification->data['title'])->toBe('Loaner overdue: LN-77')
        ->and($notification->data['message'])->toContain('9 days');
});
