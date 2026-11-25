<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Listeners;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyTriggerEvent;
use Modules\CustomerSatisfaction\Services\SurveyScheduler;
use Modules\CustomerSatisfaction\Services\SurveyTriggerContext;
use Modules\ServiceManagement\Events\TicketClosedEvent;
use Modules\ServiceManagement\Events\WorkOrderCompletedEvent;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\WorkOrder;
use Throwable;

/**
 * A closed helpdesk ticket (Mod 126) or a completed field intervention (Mod 057) schedules the satisfaction survey of its customer.
 * A failure is reported but never blocks the ticket or the work order.
 */
final readonly class ScheduleSurveyOnServiceEvents
{
    public function __construct(private TenantContext $tenantContext, private SurveyScheduler $scheduler) {}

    public function handleTicketClosed(TicketClosedEvent $event): void
    {
        $this->run($event->tenantId, function () use ($event): void {
            $ticket = Ticket::query()->find($event->ticketId);

            if ($ticket === null || blank($ticket->customer_id)) {
                return;
            }

            $this->scheduler->handle(new SurveyTriggerContext(
                SurveyTriggerEvent::TicketClosed,
                (string) $ticket->customer_id,
                'esm_ticket',
                (string) $ticket->id,
                null,
                ['technician_id' => $ticket->assigned_technician_id],
            ));
        });
    }

    public function handleWorkOrderCompleted(WorkOrderCompletedEvent $event): void
    {
        $this->run($event->tenantId, function () use ($event): void {
            $workOrder = WorkOrder::query()->find($event->workOrderId);

            if ($workOrder === null || blank($workOrder->customer_id)) {
                return;
            }

            $this->scheduler->handle(new SurveyTriggerContext(
                SurveyTriggerEvent::FieldInterventionSigned,
                (string) $workOrder->customer_id,
                'esm_work_order',
                (string) $workOrder->id,
                null,
                ['technician_id' => $workOrder->assigned_technician_id, 'amount' => $event->totalCost],
            ));
        });
    }

    private function run(string $tenantId, callable $callback): void
    {
        try {
            $tenant = Tenant::query()->find($tenantId);

            if ($tenant !== null) {
                $this->tenantContext->runWithTenant($tenant, $callback);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
