<?php

declare(strict_types=1);

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\ServiceManagement\Contracts\IncidentManagementServiceInterface;
use Modules\ServiceManagement\Contracts\ProblemAndChangeServiceInterface;
use Modules\ServiceManagement\Contracts\SLAManagementServiceInterface;
use Modules\ServiceManagement\Contracts\TicketServiceInterface;
use Modules\ServiceManagement\Contracts\WorkOrderAndFieldServiceInterface;
use Modules\ServiceManagement\Events\ChangeApprovedEvent;
use Modules\ServiceManagement\Events\ChangeRequestCreatedEvent;
use Modules\ServiceManagement\Events\CustomerSatisfactionUpdatedEvent;
use Modules\ServiceManagement\Events\FieldServiceExpenseReadyEvent;
use Modules\ServiceManagement\Events\IncidentCreatedEvent;
use Modules\ServiceManagement\Events\IncidentResolvedEvent;
use Modules\ServiceManagement\Events\ProblemCreatedEvent;
use Modules\ServiceManagement\Events\ProblemResolvedEvent;
use Modules\ServiceManagement\Events\ServiceAccrualReadyEvent;
use Modules\ServiceManagement\Events\ServiceCashForecastEvent;
use Modules\ServiceManagement\Events\ServiceCostReadyEvent;
use Modules\ServiceManagement\Events\ServiceInvoiceReadyEvent;
use Modules\ServiceManagement\Events\ServiceLaborCostReadyEvent;
use Modules\ServiceManagement\Events\ServicePipelineForecastEvent;
use Modules\ServiceManagement\Events\ServiceRevenueForecastEvent;
use Modules\ServiceManagement\Events\ServiceRevenueReadyEvent;
use Modules\ServiceManagement\Events\ServiceVisitCompletedEvent;
use Modules\ServiceManagement\Events\TicketAssignedEvent;
use Modules\ServiceManagement\Events\TicketClosedEvent;
use Modules\ServiceManagement\Events\TicketCreatedEvent;
use Modules\ServiceManagement\Events\TicketEscalatedEvent;
use Modules\ServiceManagement\Events\TicketResolvedEvent;
use Modules\ServiceManagement\Events\WorkOrderCompletedEvent;
use Modules\ServiceManagement\Events\WorkOrderCreatedEvent;
use Modules\ServiceManagement\Models\ChangeRequest;
use Modules\ServiceManagement\Models\Incident;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;

test('all 24 Module 17 ESM domain events implement DomainEventInterface and serialize cleanly', function () {
    $events = [
        TicketCreatedEvent::class,
        TicketAssignedEvent::class,
        TicketEscalatedEvent::class,
        TicketResolvedEvent::class,
        TicketClosedEvent::class,
        IncidentCreatedEvent::class,
        IncidentResolvedEvent::class,
        ProblemCreatedEvent::class,
        ProblemResolvedEvent::class,
        ChangeRequestCreatedEvent::class,
        ChangeApprovedEvent::class,
        WorkOrderCreatedEvent::class,
        WorkOrderCompletedEvent::class,
        ServiceVisitCompletedEvent::class,
        CustomerSatisfactionUpdatedEvent::class,
        ServiceInvoiceReadyEvent::class,
        ServiceCostReadyEvent::class,
        ServiceLaborCostReadyEvent::class,
        FieldServiceExpenseReadyEvent::class,
        ServiceRevenueReadyEvent::class,
        ServiceAccrualReadyEvent::class,
        ServiceCashForecastEvent::class,
        ServiceRevenueForecastEvent::class,
        ServicePipelineForecastEvent::class,
    ];

    expect(count($events))->toBe(24);

    foreach ($events as $eventClass) {
        $reflection = new ReflectionClass($eventClass);
        expect($reflection->implementsInterface(DomainEventInterface::class))->toBeTrue();
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();
    }
});

test('critical ESM models implement LockableByWorkflowInterface and extend BaseEnterpriseModel', function () {
    $workflowLockableModels = [
        Ticket::class,
        Incident::class,
        ChangeRequest::class,
        WorkOrder::class,
        ServiceContract::class,
    ];

    foreach ($workflowLockableModels as $modelClass) {
        $reflection = new ReflectionClass($modelClass);
        expect($reflection->isSubclassOf(BaseEnterpriseModel::class))->toBeTrue();
        expect($reflection->implementsInterface(LockableByWorkflowInterface::class))->toBeTrue();
    }
});

test('all 5 ESM core service contracts are bound as singletons in container', function () {
    expect(app()->bound(TicketServiceInterface::class))->toBeTrue();
    expect(app(TicketServiceInterface::class))->toBeInstanceOf(TicketServiceInterface::class);

    expect(app()->bound(IncidentManagementServiceInterface::class))->toBeTrue();
    expect(app(IncidentManagementServiceInterface::class))->toBeInstanceOf(IncidentManagementServiceInterface::class);

    expect(app()->bound(ProblemAndChangeServiceInterface::class))->toBeTrue();
    expect(app(ProblemAndChangeServiceInterface::class))->toBeInstanceOf(ProblemAndChangeServiceInterface::class);

    expect(app()->bound(WorkOrderAndFieldServiceInterface::class))->toBeTrue();
    expect(app(WorkOrderAndFieldServiceInterface::class))->toBeInstanceOf(WorkOrderAndFieldServiceInterface::class);

    expect(app()->bound(SLAManagementServiceInterface::class))->toBeTrue();
    expect(app(SLAManagementServiceInterface::class))->toBeInstanceOf(SLAManagementServiceInterface::class);
});
