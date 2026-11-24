<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Contracts;

use Modules\Invoicing\Models\SalesInvoice;
use Modules\ServiceManagement\DTOs\CompleteWorkOrderDTO;
use Modules\ServiceManagement\DTOs\CreateWorkOrderDTO;
use Modules\ServiceManagement\DTOs\RecordVisitDTO;
use Modules\ServiceManagement\DTOs\ScheduleAppointmentDTO;
use Modules\ServiceManagement\Models\PartsConsumption;
use Modules\ServiceManagement\Models\ServiceAppointment;
use Modules\ServiceManagement\Models\ServiceVisit;
use Modules\ServiceManagement\Models\WorkOrder;

interface WorkOrderAndFieldServiceInterface
{
    public function createWorkOrder(CreateWorkOrderDTO $dto, ?string $actorUserId = null): WorkOrder;

    public function scheduleAppointment(ScheduleAppointmentDTO $dto, ?string $actorUserId = null): ServiceAppointment;

    public function recordVisit(RecordVisitDTO $dto, ?string $actorUserId = null): ServiceVisit;

    public function consumePart(
        string $workOrderId,
        string $itemNumber,
        string $description,
        string|int|float $quantity,
        string|int|float $unitCost,
        string|int|float $unitPrice,
        ?string $warehouseId = null,
        ?string $actorUserId = null
    ): PartsConsumption;

    public function completeWorkOrder(CompleteWorkOrderDTO $dto, ?string $actorUserId = null): WorkOrder;

    public function markInvoiceReady(string $workOrderId, ?string $actorUserId = null): void;

    /**
     * Drafts the sales invoice of a completed work order through the invoicing service and links it to the work order.
     */
    public function invoiceWorkOrder(string $workOrderId, string $taxRate, ?string $dueDate = null, ?string $revenueAccountId = null, ?string $actorUserId = null): SalesInvoice;
}
