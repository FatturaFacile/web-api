<?php

declare(strict_types=1);

namespace Modules\CRM\Listeners;

use Modules\CRM\Contracts\CustomerHealthServiceInterface;
use Modules\CRM\DTOs\EvaluateHealthDTO;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\CustomerHealthProfile;
use Modules\ServiceManagement\Events\TicketResolvedEvent;
use Modules\ServiceManagement\Events\WorkOrderCompletedEvent;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\WorkOrder;
use Throwable;

/**
 * Keeps the customer health score and churn risk current when post-sales work finishes: a resolved ticket or a
 * completed field intervention re-evaluates the account behind the customer. A failure here is reported but never
 * blocks the ticket or the work order.
 */
final readonly class RefreshCustomerHealthListener
{
    public function __construct(private CustomerHealthServiceInterface $health) {}

    public function handleTicketResolved(TicketResolvedEvent $event): void
    {
        $ticket = Ticket::query()->find($event->ticketId);

        $this->refresh($event->tenantId, $ticket?->customer_id);
    }

    public function handleWorkOrderCompleted(WorkOrderCompletedEvent $event): void
    {
        $workOrder = WorkOrder::query()->find($event->workOrderId);

        $this->refresh($event->tenantId, $workOrder?->customer_id);
    }

    private function refresh(string $tenantId, ?string $contactId): void
    {
        if ($contactId === null || $contactId === '') {
            return;
        }

        try {
            $account = Account::query()->where('tenant_id', $tenantId)->where('contact_id', $contactId)->first();
            if ($account !== null) {
                // The receivables come from the invoicing events: keep what the profile already holds.
                $profile = CustomerHealthProfile::query()->where('tenant_id', $tenantId)->where('account_id', $account->id)->first();

                $this->health->evaluateHealth($account, new EvaluateHealthDTO(
                    accountId: (string) $account->id,
                    openReceivableAmount: $profile !== null ? (float) $profile->open_receivable_amount : null,
                    overdueReceivableAmount: $profile !== null ? (float) $profile->overdue_receivable_amount : null,
                ));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
