<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Carbon;
use Modules\ServiceManagement\Contracts\SLAManagementServiceInterface;
use Modules\ServiceManagement\Events\TicketEscalatedEvent;
use Modules\ServiceManagement\Models\SLAPolicy;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Services\Helpdesk\TicketSlaClock;

final readonly class SLAManagementService implements SLAManagementServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private TicketSlaClock $slaClock,
        private TenantContext $tenantContext,
    ) {}

    /**
     * Due date of a response or resolution target. The minutes of the policy run on the tenant's business hours when
     * the policy counts business hours only and the helpdesk settings name a calendar; otherwise around the clock.
     */
    public function calculateDueDate(?string $slaPolicyId, string $priority, string $type = 'resolution'): ?string
    {
        $minutes = null;
        $policy = null;

        if ($slaPolicyId !== null) {
            $policy = SLAPolicy::find($slaPolicyId);
            if ($policy !== null) {
                $minutes = $type === 'response' ? $policy->response_time_minutes : $policy->resolution_time_minutes;
            }
        }

        if ($minutes === null) {
            $policy = SLAPolicy::where('priority', $priority)->where('is_active', true)->first();
            if ($policy !== null) {
                $minutes = $type === 'response' ? $policy->response_time_minutes : $policy->resolution_time_minutes;
            }
        }

        if ($minutes === null) {
            $minutes = match (strtolower($priority)) {
                'critical' => $type === 'response' ? 60 : 240,
                'high' => $type === 'response' ? 120 : 480,
                'low' => $type === 'response' ? 480 : 2880,
                default => $type === 'response' ? 240 : 1440,
            };
        }

        $calendar = $this->slaClock->calendarFor($policy, $this->tenantContext->getTenantId());

        return $this->slaClock->dueDate(Carbon::now(), (int) $minutes, $calendar)->toIso8601String();
    }

    public function checkAndRecordBreaches(): int
    {
        $openTickets = Ticket::whereIn('status', ['open', 'assigned', 'in_progress'])
            ->whereNotNull('resolution_due_at')
            ->where('resolution_due_at', '<', now())
            ->get();

        $count = 0;
        foreach ($openTickets as $ticket) {
            if ($ticket->is_sla_breached) {
                continue;
            }

            $ticket->is_sla_breached = true;
            $ticket->save();

            $this->auditLedger->record(
                'SLA_BREACHED',
                $ticket,
                null,
                ['resolution_due_at' => (string) $ticket->resolution_due_at],
                'MODULE_17_ESM'
            );

            $this->dispatcher->dispatch(new TicketEscalatedEvent(
                ticketId: $ticket->id,
                ticketNumber: $ticket->ticket_number,
                reason: 'Automatic SLA resolution deadline breached',
                escalationLevel: 2,
                tenantId: $ticket->tenant_id,
                companyId: $ticket->company_id ?? $ticket->tenant_id
            ));

            $count++;
        }

        return $count;
    }
}
