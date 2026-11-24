<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Listeners;

use Modules\ServiceManagement\Events\ContractHoursDeducted;
use Modules\ServiceManagement\Events\WorkOrderCompletedEvent;
use Modules\ServiceManagement\Exceptions\HourBankException;
use Modules\ServiceManagement\Jobs\SendResidualHoursReportJob;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\ServiceManagement\Services\HourBankService;

/**
 * Glue between work orders and the hour bank: a completed work order books its labor hours on the covering
 * maintenance contract, and every deduction queues the residual-hours report for the customer.
 */
final class ContractHoursListener
{
    public function __construct(private readonly HourBankService $hourBank) {}

    public function handleWorkOrderCompleted(WorkOrderCompletedEvent $event): void
    {
        $workOrder = WorkOrder::query()->with(['technician', 'ticket'])->find($event->workOrderId);
        if ($workOrder === null || $event->totalLaborHours <= 0) {
            return;
        }

        $contractId = $workOrder->getAttribute('service_contract_id') ?? $workOrder->ticket?->getAttribute('service_contract_id');
        $contract = $contractId !== null ? ServiceContract::query()->find($contractId) : null;
        if ($contract === null || bccomp((string) $contract->getAttribute('included_hours'), '0', 2) <= 0) {
            return;
        }

        try {
            $this->hourBank->deduct($contract, number_format($event->totalLaborHours, 2, '.', ''), [
                'work_order_id' => $workOrder->id,
                'ticket_id' => $workOrder->ticket_id,
                'equipment_passport_id' => $workOrder->getAttribute('equipment_passport_id'),
                'technician_name' => $workOrder->technician?->getAttribute('name'),
                'reference' => $workOrder->work_order_number,
            ]);
        } catch (HourBankException $e) {
            // Never block the work order completion: the unbooked hours stay visible on the work order itself.
            report($e);
        }
    }

    public function handleHoursDeducted(ContractHoursDeducted $event): void
    {
        SendResidualHoursReportJob::dispatch($event->contractId, $event->tenantId);
    }
}
