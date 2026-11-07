<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\ServiceManagement\Enums\TicketStatus;
use Modules\ServiceManagement\Models\BusinessHoursCalendar;
use Modules\ServiceManagement\Models\HelpdeskSetting;
use Modules\ServiceManagement\Models\SLAPolicy;
use Modules\ServiceManagement\Models\Ticket;

/**
 * SLA clock of a ticket: due dates on the tenant's business hours, the pause while the customer is expected to answer,
 * and the SLA state shown in lists and filters.
 */
final readonly class TicketSlaClock
{
    public const string StateOk = 'ok';

    public const string StateWarning = 'warning';

    public const string StateBreached = 'breached';

    public const string StatePaused = 'paused';

    public const string StateMet = 'met';

    public function __construct(private BusinessHoursClock $clock) {}

    /**
     * The calendar the SLA of a policy runs on: the tenant's business hours, unless the policy runs around the clock.
     */
    public function calendarFor(?SLAPolicy $policy, ?string $tenantId): ?BusinessHoursCalendar
    {
        if ($policy !== null && ! $policy->business_hours_only) {
            return null;
        }

        $settings = HelpdeskSetting::current($tenantId);

        return $settings->business_hours_calendar_id === null ? null : BusinessHoursCalendar::query()->find($settings->business_hours_calendar_id);
    }

    public function calendarForTicket(Ticket $ticket): ?BusinessHoursCalendar
    {
        $metadata = is_array($ticket->metadata) ? $ticket->metadata : [];
        $policyId = $metadata['sla_policy_id'] ?? null;
        $policy = is_string($policyId) ? SLAPolicy::query()->find($policyId) : null;

        return $this->calendarFor($policy, $ticket->tenant_id);
    }

    public function dueDate(DateTimeInterface $start, int $minutes, ?BusinessHoursCalendar $calendar): CarbonImmutable
    {
        return $this->clock->addBusinessMinutes($start, $minutes, $calendar);
    }

    public function elapsedMinutes(DateTimeInterface $from, DateTimeInterface $to, ?BusinessHoursCalendar $calendar): int
    {
        return $this->clock->businessMinutesBetween($from, $to, $calendar);
    }

    /**
     * Stops the clock: the ticket waits for the customer.
     */
    public function pause(Ticket $ticket): void
    {
        if ($ticket->sla_paused_at === null) {
            $ticket->sla_paused_at = now();
        }
    }

    /**
     * Restarts the clock: the deadlines move forward by the business time spent waiting, so the time left when the
     * clock stopped is still left now.
     */
    public function resume(Ticket $ticket): void
    {
        if ($ticket->sla_paused_at === null) {
            return;
        }

        $pausedAt = CarbonImmutable::instance($ticket->sla_paused_at);
        $now = CarbonImmutable::now();
        $calendar = $this->calendarForTicket($ticket);

        if ($ticket->first_responded_at === null && $ticket->response_due_at !== null && $ticket->response_due_at->greaterThan($pausedAt)) {
            $ticket->response_due_at = $this->clock->addBusinessMinutes($now, $this->clock->businessMinutesBetween($pausedAt, $ticket->response_due_at, $calendar), $calendar);
        }

        if ($ticket->resolution_due_at !== null && $ticket->resolution_due_at->greaterThan($pausedAt)) {
            $ticket->resolution_due_at = $this->clock->addBusinessMinutes($now, $this->clock->businessMinutesBetween($pausedAt, $ticket->resolution_due_at, $calendar), $calendar);
        }

        $ticket->sla_paused_minutes = (int) $ticket->sla_paused_minutes + $this->clock->businessMinutesBetween($pausedAt, $now, $calendar);
        $ticket->sla_paused_at = null;
    }

    /**
     * SLA state of a ticket: met once resolved in time, paused while waiting for the customer, breached past a
     * deadline, warning past the warning share of a target.
     */
    public function state(Ticket $ticket, ?int $warningPercent = null): string
    {
        $status = $ticket->statusEnum();
        $now = CarbonImmutable::now();

        if (! $status->isActive()) {
            return $ticket->resolution_due_at !== null && $ticket->resolved_at !== null && $ticket->resolved_at->greaterThan($ticket->resolution_due_at) ? self::StateBreached : self::StateMet;
        }

        if ($status === TicketStatus::PendingCustomer || $ticket->sla_paused_at !== null) {
            return self::StatePaused;
        }

        $responseDue = $ticket->first_responded_at === null ? $ticket->response_due_at : null;

        foreach ([$responseDue, $ticket->resolution_due_at] as $due) {
            if ($due !== null && $due->lessThanOrEqualTo($now)) {
                return self::StateBreached;
            }
        }

        $warningPercent ??= HelpdeskSetting::current($ticket->tenant_id)->sla_warning_percent;

        foreach ([$responseDue, $ticket->resolution_due_at] as $due) {
            if ($due !== null && $ticket->created_at !== null && $this->isPastShare($ticket->created_at, $due, $now, $warningPercent)) {
                return self::StateWarning;
            }
        }

        return self::StateOk;
    }

    /**
     * Whether the share of the wall-clock span from start to due has passed.
     */
    public function isPastShare(DateTimeInterface $start, DateTimeInterface $due, DateTimeInterface $now, int $percent): bool
    {
        $span = $due->getTimestamp() - $start->getTimestamp();

        return $span > 0 && ($now->getTimestamp() - $start->getTimestamp()) >= $span * $percent / 100;
    }
}
