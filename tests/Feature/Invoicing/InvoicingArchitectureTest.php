<?php

declare(strict_types=1);

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\Invoicing\Contracts\CustomerCreditServiceInterface;
use Modules\Invoicing\Contracts\CustomerLedgerServiceInterface;
use Modules\Invoicing\Contracts\DeliveryFulfillmentServiceInterface;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Contracts\PricingAndDiscountServiceInterface;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Contracts\SalesQuoteServiceInterface;
use Modules\Invoicing\Events\CreditNoteIssuedEvent;
use Modules\Invoicing\Events\CustomerCreditLimitUpdatedEvent;
use Modules\Invoicing\Events\DeliveryNoteIssuedEvent;
use Modules\Invoicing\Events\GoodsDispatchedEvent;
use Modules\Invoicing\Events\InvoiceApprovedEvent;
use Modules\Invoicing\Events\InvoiceDraftedEvent;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Events\SalesOrderApprovedEvent;
use Modules\Invoicing\Events\SalesOrderCreatedEvent;
use Modules\Invoicing\Events\SalesOrderCreditCheckedEvent;
use Modules\Invoicing\Events\SalesOrderHoldPlacedEvent;
use Modules\Invoicing\Events\SalesOrderHoldReleasedEvent;
use Modules\Invoicing\Events\SalesOrderStockReservedEvent;
use Modules\Invoicing\Events\SalesQuoteAcceptedEvent;
use Modules\Invoicing\Events\SalesQuoteApprovedEvent;
use Modules\Invoicing\Events\SalesQuoteCreatedEvent;
use Modules\Invoicing\Models\BillingSchedule;
use Modules\Invoicing\Models\BillingScheduleItem;
use Modules\Invoicing\Models\CustomerAgreement;
use Modules\Invoicing\Models\CustomerCreditProfile;
use Modules\Invoicing\Models\CustomerGroup;
use Modules\Invoicing\Models\CustomerLedgerEntry;
use Modules\Invoicing\Models\DiscountPolicy;
use Modules\Invoicing\Models\DiscountPolicyRule;
use Modules\Invoicing\Models\InvoiceCreditApplication;
use Modules\Invoicing\Models\SalesCommission;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesDeliveryNoteItem;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesInvoiceItem;
use Modules\Invoicing\Models\SalesOpportunity;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderHold;
use Modules\Invoicing\Models\SalesOrderItem;
use Modules\Invoicing\Models\SalesPriceList;
use Modules\Invoicing\Models\SalesPriceListItem;
use Modules\Invoicing\Models\SalesQuote;
use Modules\Invoicing\Models\SalesQuoteItem;
use Modules\Invoicing\Models\SalesRepresentative;
use Modules\Invoicing\Services\CustomerCreditService;
use Modules\Invoicing\Services\CustomerLedgerService;
use Modules\Invoicing\Services\DeliveryFulfillmentService;
use Modules\Invoicing\Services\InvoicingService;
use Modules\Invoicing\Services\PricingAndDiscountService;
use Modules\Invoicing\Services\SalesOrderService;
use Modules\Invoicing\Services\SalesQuoteService;

test('all invoicing models extend BaseEnterpriseModel', function (): void {
    $models = [
        CustomerGroup::class,
        SalesPriceList::class,
        SalesPriceListItem::class,
        DiscountPolicy::class,
        DiscountPolicyRule::class,
        CustomerAgreement::class,
        CustomerCreditProfile::class,
        SalesRepresentative::class,
        SalesCommission::class,
        SalesOrderHold::class,
        CustomerLedgerEntry::class,
        BillingSchedule::class,
        BillingScheduleItem::class,
        InvoiceCreditApplication::class,
        SalesOpportunity::class,
        SalesQuote::class,
        SalesQuoteItem::class,
        SalesOrder::class,
        SalesOrderItem::class,
        SalesDeliveryNote::class,
        SalesDeliveryNoteItem::class,
        SalesInvoice::class,
        SalesInvoiceItem::class,
    ];

    foreach ($models as $modelClass) {
        $reflection = new ReflectionClass($modelClass);
        expect($reflection->isSubclassOf(BaseEnterpriseModel::class))->toBeTrue("{$modelClass} must extend BaseEnterpriseModel");
    }
});

test('all invoicing contracts are bound to concrete domain services', function (): void {
    expect(app(PricingAndDiscountServiceInterface::class))->toBeInstanceOf(PricingAndDiscountService::class);
    expect(app(CustomerLedgerServiceInterface::class))->toBeInstanceOf(CustomerLedgerService::class);
    expect(app(CustomerCreditServiceInterface::class))->toBeInstanceOf(CustomerCreditService::class);
    expect(app(SalesQuoteServiceInterface::class))->toBeInstanceOf(SalesQuoteService::class);
    expect(app(SalesOrderServiceInterface::class))->toBeInstanceOf(SalesOrderService::class);
    expect(app(DeliveryFulfillmentServiceInterface::class))->toBeInstanceOf(DeliveryFulfillmentService::class);
    expect(app(InvoicingServiceInterface::class))->toBeInstanceOf(InvoicingService::class);
});

test('all invoicing domain events implement DomainEventInterface', function (): void {
    $events = [
        SalesQuoteCreatedEvent::class,
        SalesQuoteApprovedEvent::class,
        SalesQuoteAcceptedEvent::class,
        SalesOrderCreatedEvent::class,
        SalesOrderCreditCheckedEvent::class,
        SalesOrderHoldPlacedEvent::class,
        SalesOrderHoldReleasedEvent::class,
        SalesOrderApprovedEvent::class,
        SalesOrderStockReservedEvent::class,
        DeliveryNoteIssuedEvent::class,
        GoodsDispatchedEvent::class,
        InvoiceDraftedEvent::class,
        InvoiceApprovedEvent::class,
        InvoicePostedEvent::class,
        CreditNoteIssuedEvent::class,
        CustomerCreditLimitUpdatedEvent::class,
    ];

    foreach ($events as $eventClass) {
        $reflection = new ReflectionClass($eventClass);
        expect($reflection->implementsInterface(DomainEventInterface::class))->toBeTrue("{$eventClass} must implement DomainEventInterface");
    }
});

test('invoicing database seeder executes without errors', function (): void {
    $this->artisan('db:seed', ['--class' => 'InvoicingDatabaseSeeder'])
        ->assertSuccessful();
});
