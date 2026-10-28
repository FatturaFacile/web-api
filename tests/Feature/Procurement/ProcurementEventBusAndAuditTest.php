<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\InventoryReceiptRequestedEvent;
use Modules\Procurement\Events\InventoryReservationRequestedEvent;
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;
use Modules\Procurement\Events\PurchaseOrderApprovedEvent;
use Modules\Procurement\Events\PurchaseOrderCommittedEvent;
use Modules\Procurement\Events\PurchaseOrderCreatedEvent;
use Modules\Procurement\Events\PurchaseRequisitionApprovedEvent;
use Modules\Procurement\Events\PurchaseRequisitionCreatedEvent;
use Modules\Procurement\Events\SupplierApprovedEvent;
use Modules\Procurement\Events\SupplierContractExpiredEvent;
use Modules\Procurement\Events\SupplierCreatedEvent;
use Modules\Procurement\Events\SupplierSuspendedEvent;
use Modules\Procurement\Events\ThreeWayMatchDiscrepancyDetectedEvent;
use Modules\Procurement\Events\ThreeWayMatchPassedEvent;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Services\ProcurementAuditLedgerService;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    Gate::before(fn () => true);

    $this->tenant = Tenant::first() ?? Tenant::create([
        'name' => 'Event Bus Tenant',
        'legal_name' => 'Event Bus Corp',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::first() ?? User::create([
        'name' => 'Event Auditor',
        'email' => 'auditor@procurement.test',
        'password' => bcrypt('password123'),
    ]);

    Sanctum::actingAs($this->user);
});

test('all procurement domain events implement DomainEventInterface', function (): void {
    $events = [
        new SupplierCreatedEvent('id', 'SUP-01', 'Acme', 'IT', 'EUR', 't1', 'c1'),
        new SupplierApprovedEvent('id', 'SUP-01', 'u1', 't1', 'c1'),
        new SupplierSuspendedEvent('id', 'SUP-01', 'Violation', 't1', 'c1'),
        new SupplierContractExpiredEvent('id', 'CTR-01', 's1', 't1', 'c1'),
        new PurchaseRequisitionCreatedEvent('id', 'REQ-01', 'u1', 100.0, 'EUR', 't1', 'c1'),
        new PurchaseRequisitionApprovedEvent('id', 'REQ-01', 'u1', 't1', 'c1'),
        new PurchaseOrderCreatedEvent('id', 'PO-01', 's1', 500.0, 'EUR', 't1', 'c1'),
        new PurchaseOrderApprovedEvent('id', 'PO-01', 'u1', 500.0, 'EUR', 't1', 'c1'),
        new PurchaseOrderCommittedEvent('id', 'PO-01', 's1', 500.0, 'EUR', 't1', 'c1'),
        new GoodsReceivedEvent('id', 'GRN-01', 'po1', 'DDT-01', [], 't1', 'c1'),
        new InventoryReceiptRequestedEvent('id', 'po1', 'wh1', [], 't1', 'c1'),
        new InventoryReservationRequestedEvent('po1', 'item1', 'wh1', 10.0, 't1', 'c1'),
        new ThreeWayMatchPassedEvent('id', 'po1', 's1', 100.0, 't1', 'c1'),
        new ThreeWayMatchDiscrepancyDetectedEvent('id', 'po1', 's1', 100.0, 110.0, 10.0, 0.0, 't1', 'c1'),
        new PurchaseLiabilityReadyEvent('id', 'po1', 's1', 100.0, 22.0, 122.0, 'EUR', 'INV-01', 't1', 'c1'),
    ];

    foreach ($events as $event) {
        expect($event)->toBeInstanceOf(DomainEventInterface::class);
        expect($event->getEventName())->toBeString()->not->toBeEmpty();
        expect($event->getAggregateType())->toBeString()->not->toBeEmpty();
        expect($event->getAggregateId())->toBeString()->not->toBeEmpty();
        expect($event->toPayload())->toBeArray();
    }
});

test('procurement audit ledger records business transactions cleanly', function (): void {
    $auditMock = Mockery::mock(AuditLedgerInterface::class);
    $auditMock->shouldReceive('record')
        ->once()
        ->with('SUPPLIER_STATUS_CHANGED', Mockery::type(Supplier::class), Mockery::type('array'), Mockery::type('array'), 'PROCUREMENT');

    $service = new ProcurementAuditLedgerService($auditMock);
    $supplier = Supplier::factory()->create();

    $service->record(
        action: 'SUPPLIER_STATUS_CHANGED',
        model: $supplier,
        payloadBefore: ['status' => 'draft'],
        payloadAfter: ['status' => 'active']
    );

    expect(true)->toBeTrue();
});
