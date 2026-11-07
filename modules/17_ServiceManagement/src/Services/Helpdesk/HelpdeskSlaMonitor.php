<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\ServiceManagement\Enums\TicketStatus;
use Modules\ServiceManagement\Events\HelpdeskTicketChanged;
use Modules\ServiceManagement\Events\TicketEscalatedEvent;
use Modules\ServiceManagement\Models\HelpdeskSetting;
use Modules\ServiceManagement\Models\Ticket;

/**
 * Watches the first response and resolution targets of the active tickets of a tenant: a warning once the warning share
 * of a target has passed, a breach with escalation once it is due. Each notice is stamped on the ticket, so it is sent
 * once; a ticket waiting for the customer is skipped while its clock is stopped.
 */
final readonly class HelpdeskSlaMonitor
{
    public function __construct(
        private TicketSlaClock $slaClock,
        private TicketActivityLog $activityLog,
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
    ) {}

    /**
     * @return array{warnings: int, breaches: int}
     */
    public function scan(string $tenantId): array
    {
        $settings = HelpdeskSetting::current($tenantId);
        $result = ['warnings' => 0, 'breaches' => 0];

        $candidates = Ticket::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', TicketStatus::activeValues())
            ->where('status', '!=', TicketStatus::PendingCustomer->value)
            ->whereNull('sla_paused_at')
            ->whereNull('merged_into_ticket_id')
            ->where(fn ($query) => $query
                ->where(fn ($response) => $response->whereNull('first_responded_at')->whereNotNull('response_due_at')->whereNull('response_breached_at'))
                ->orWhere(fn ($resolution) => $resolution->whereNotNull('resolution_due_at')->whereNull('resolution_breached_at')))
            ->pluck('id');

        foreach ($candidates as $ticketId) {
            $outcome = DB::transaction(fn (): array => $this->check((string) $ticketId, $settings->sla_warning_percent));
            $result['warnings'] += $outcome['warnings'];
            $result['breaches'] += $outcome['breaches'];
        }

        return $result;
    }

    /**
     * @return array{warnings: int, breaches: int}
     */
    private function check(string $ticketId, int $warningPercent): array
    {
        $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->first();
        $outcome = ['warnings' => 0, 'breaches' => 0];

        if ($ticket === null || ! $ticket->statusEnum()->isActive() || $ticket->sla_paused_at !== null || $ticket->statusEnum() === TicketStatus::PendingCustomer) {
            return $outcome;
        }

        $now = CarbonImmutable::now();
        $start = $ticket->created_at ?? $now;

        $targets = [
            'response' => $ticket->first_responded_at === null ? $ticket->response_due_at : null,
            'resolution' => $ticket->resolution_due_at,
        ];

        foreach ($targets as $target => $due) {
            if ($due === null || $ticket->getAttribute("{$target}_breached_at") !== null) {
                continue;
            }

            if ($due->lessThanOrEqualTo($now)) {
                $this->breach($ticket, $target, $due);
                $outcome['breaches']++;

                continue;
            }

            if ($ticket->getAttribute("{$target}_warning_sent_at") === null && $this->slaClock->isPastShare($start, $due, $now, $warningPercent)) {
                $ticket->setAttribute("{$target}_warning_sent_at", $now);
                $ticket->save();
                $this->activityLog->record($ticket, 'sla_warning', null, $target, ['due_at' => $due->toIso8601String()]);
                event(new HelpdeskTicketChanged($ticket->id, $ticket->tenant_id, HelpdeskTicketChanged::SlaWarning, null, ['target' => $target, 'due_at' => $due->toIso8601String()]));
                $outcome['warnings']++;
            }
        }

        return $outcome;
    }

    private function breach(Ticket $ticket, string $target, DateTimeInterface $due): void
    {
        $ticket->setAttribute("{$target}_breached_at", now());
        $ticket->is_escalated = true;
        $ticket->escalation_level = (int) $ticket->escalation_level + 1;

        $alreadyFlagged = (bool) $ticket->is_sla_breached;

        if ($target === 'resolution') {
            $ticket->is_sla_breached = true;
        }

        $ticket->save();

        $this->activityLog->record($ticket, 'sla_breached', null, $target, ['due_at' => $due->format(DATE_ATOM), 'escalation_level' => $ticket->escalation_level]);
        $this->auditLedger->record('SLA_BREACHED', $ticket, null, ['target' => $target, 'due_at' => $due->format(DATE_ATOM)], 'MODULE_17_ESM');

        if ($target === 'response' || ! $alreadyFlagged) {
            $this->dispatcher->dispatch(new TicketEscalatedEvent(
                ticketId: $ticket->id,
                ticketNumber: $ticket->ticket_number,
                reason: "Automatic SLA {$target} deadline breached",
                escalationLevel: $ticket->escalation_level,
                tenantId: $ticket->tenant_id,
                companyId: $ticket->company_id ?? $ticket->tenant_id
            ));
        }

        event(new HelpdeskTicketChanged($ticket->id, $ticket->tenant_id, HelpdeskTicketChanged::SlaBreached, null, ['target' => $target, 'escalation_level' => $ticket->escalation_level]));
    }
}
