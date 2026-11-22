<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\ServiceManagement\Enums\TicketStatus;
use Modules\ServiceManagement\Events\SlaEscalationStepFired;
use Modules\ServiceManagement\Models\EscalationChain;
use Modules\ServiceManagement\Models\EscalationStep;
use Modules\ServiceManagement\Models\HelpdeskNotification;
use Modules\ServiceManagement\Models\ServiceTeam;
use Modules\ServiceManagement\Models\SLAPolicy;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketEscalationLog;
use Modules\ServiceManagement\Models\TicketMessage;

/**
 * Runs the escalation chains of the SLA policies (#43). A step fires once the elapsed share of its target (first
 * response, next update or resolution) reaches its threshold: 75 warns before the breach, 100 is the breach, above 100
 * escalates after it. A step notifies managers, reassigns the ticket to another team or raises its priority.
 *
 * Each fired step writes one immutable log row. The unique key (ticket, step, cycle) makes the scan idempotent: a repeated
 * or concurrent scan never fires a step twice, nor notifies twice. Tickets whose clock is stopped are skipped.
 */
final readonly class EscalationChainEvaluator
{
    public const string EventName = 'sla_escalation';

    public function __construct(
        private TicketActivityLog $activityLog,
        private TicketAssignmentService $assignment,
    ) {}

    /**
     * @return array{fired: int}
     */
    public function scan(string $tenantId, ?DateTimeInterface $now = null): array
    {
        $now = $now === null ? CarbonImmutable::now() : CarbonImmutable::instance($now);
        $chains = $this->chainsByPolicy($tenantId);
        $fired = 0;

        $this->markNextUpdateBreaches($tenantId, $now);

        if ($chains === []) {
            return ['fired' => 0];
        }

        Ticket::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', TicketStatus::activeValues())
            ->where('status', '!=', TicketStatus::PendingCustomer->value)
            ->whereNull('sla_paused_at')
            ->whereNull('merged_into_ticket_id')
            ->orderBy('id')
            ->chunkById(200, function ($tickets) use ($chains, $now, &$fired): void {
                foreach ($tickets as $ticket) {
                    $policyId = is_array($ticket->metadata) ? ($ticket->metadata['sla_policy_id'] ?? null) : null;
                    $chain = is_string($policyId) ? ($chains[$policyId] ?? null) : null;

                    if ($chain !== null) {
                        $fired += $this->evaluate($ticket->id, $chain, $now);
                    }
                }
            });

        return ['fired' => $fired];
    }

    /**
     * Stamps the next-update breach once, whatever the chain does about it.
     */
    private function markNextUpdateBreaches(string $tenantId, CarbonImmutable $now): void
    {
        Ticket::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', TicketStatus::activeValues())
            ->where('status', '!=', TicketStatus::PendingCustomer->value)
            ->whereNull('sla_paused_at')
            ->whereNotNull('next_update_due_at')
            ->whereNull('next_update_breached_at')
            ->where('next_update_due_at', '<=', $now)
            ->update(['next_update_breached_at' => $now]);
    }

    /**
     * Evaluates one ticket against a chain. Returns the number of steps fired.
     */
    private function evaluate(string $ticketId, EscalationChain $chain, CarbonImmutable $now): int
    {
        return DB::transaction(function () use ($ticketId, $chain, $now): int {
            $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->first();

            if ($ticket === null || ! $ticket->statusEnum()->isActive() || $ticket->sla_paused_at !== null || $ticket->statusEnum() === TicketStatus::PendingCustomer) {
                return 0;
            }

            $fired = 0;

            foreach ($chain->steps as $step) {
                $window = $this->window($ticket, $step->target);

                if ($window === null) {
                    continue;
                }

                [$start, $due] = $window;
                $percent = $this->elapsedPercent($start, $due, $now);

                if ($percent < $step->threshold_percent) {
                    continue;
                }

                if ($this->fire($ticket, $chain, $step, $percent, $step->target === 'next_update' ? (string) $due->getTimestamp() : '', $now)) {
                    $fired++;
                }
            }

            return $fired;
        });
    }

    /**
     * Start and due of the target of a step, or null when the target no longer runs (answered, or never set).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function window(Ticket $ticket, string $target): ?array
    {
        $created = CarbonImmutable::instance($ticket->created_at ?? now());

        return match ($target) {
            'response' => $ticket->first_responded_at === null && $ticket->response_due_at !== null
                ? [$created, CarbonImmutable::instance($ticket->response_due_at)]
                : null,
            'resolution' => $ticket->resolution_due_at !== null
                ? [$created, CarbonImmutable::instance($ticket->resolution_due_at)]
                : null,
            'next_update' => $ticket->next_update_due_at !== null
                ? [$this->lastPublicReplyAt($ticket) ?? $created, CarbonImmutable::instance($ticket->next_update_due_at)]
                : null,
            default => null,
        };
    }

    private function lastPublicReplyAt(Ticket $ticket): ?CarbonImmutable
    {
        $at = TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where('author_type', 'staff')
            ->where('visibility', 'public')
            ->max('created_at');

        return $at === null ? null : CarbonImmutable::parse($at);
    }

    private function elapsedPercent(CarbonImmutable $start, CarbonImmutable $due, CarbonImmutable $now): int
    {
        $span = $due->getTimestamp() - $start->getTimestamp();

        if ($span <= 0) {
            return $now->greaterThanOrEqualTo($due) ? 100 : 0;
        }

        return max(0, (int) floor(($now->getTimestamp() - $start->getTimestamp()) * 100 / $span));
    }

    private function fire(Ticket $ticket, EscalationChain $chain, EscalationStep $step, int $percent, string $cycleKey, CarbonImmutable $now): bool
    {
        $alreadyFired = TicketEscalationLog::query()
            ->where('ticket_id', $ticket->id)
            ->where('step_id', $step->id)
            ->where('cycle_key', $cycleKey)
            ->exists();

        if ($alreadyFired) {
            return false;
        }

        $outcome = $this->apply($ticket, $step, $percent);

        try {
            // A savepoint keeps a unique violation of a racing scan from aborting the surrounding transaction.
            DB::transaction(fn () => TicketEscalationLog::query()->create([
                'tenant_id' => $ticket->tenant_id,
                'ticket_id' => $ticket->id,
                'chain_id' => $chain->id,
                'step_id' => $step->id,
                'target' => $step->target,
                'threshold_percent' => $step->threshold_percent,
                'action' => $step->action,
                'elapsed_percent' => min($percent, 65535),
                'cycle_key' => $cycleKey,
                'outcome' => $outcome,
                'fired_at' => $now,
            ]));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        $this->activityLog->record($ticket, 'sla_escalation', $step->target, $step->action, [
            'chain' => $chain->code,
            'threshold_percent' => $step->threshold_percent,
            'elapsed_percent' => $percent,
            ...$outcome,
        ]);

        event(new SlaEscalationStepFired(
            tenantId: (string) $ticket->tenant_id,
            ticketId: $ticket->id,
            ticketNumber: (string) $ticket->ticket_number,
            priority: (string) $ticket->priority,
            target: $step->target,
            thresholdPercent: $step->threshold_percent,
            elapsedPercent: $percent,
            action: $step->action,
            recipientUserIds: $outcome['notified_user_ids'] ?? [],
        ));

        return true;
    }

    /**
     * Applies the action of a step to the locked ticket and describes what happened.
     *
     * @return array<string, mixed>
     */
    private function apply(Ticket $ticket, EscalationStep $step, int $percent): array
    {
        return match ($step->action) {
            'reassign_team' => $this->reassign($ticket, $step),
            'raise_priority' => $this->raisePriority($ticket, $step),
            default => $this->notify($ticket, $step, $percent),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function notify(Ticket $ticket, EscalationStep $step, int $percent): array
    {
        $userIds = array_values(array_filter((array) $step->notify_user_ids, is_string(...)));

        if ($step->notify_team_lead && $ticket->assigned_team_id !== null) {
            $lead = ServiceTeam::query()->whereKey($ticket->assigned_team_id)->value('lead_user_id');

            if (is_string($lead)) {
                $userIds[] = $lead;
            }
        }

        $members = $userIds === [] ? [] : DB::table('tenant_user')->where('tenant_id', $ticket->tenant_id)->whereIn('user_id', array_unique($userIds))->pluck('user_id')->all();
        $notified = [];

        foreach (User::query()->whereIn('id', $members)->get() as $user) {
            $locale = in_array($user->locale, ['it', 'de', 'fr', 'en'], true) ? $user->locale : 'en';
            $parameters = ['ticket' => $ticket->ticket_number, 'target' => trans("helpdesk_sla.target.{$step->target}", [], $locale), 'percent' => $percent];

            HelpdeskNotification::query()->create([
                'tenant_id' => $ticket->tenant_id,
                'user_id' => $user->id,
                'ticket_id' => $ticket->id,
                'event' => self::EventName,
                'title' => mb_substr(trans($percent >= 100 ? 'helpdesk_sla.escalation.breach_title' : 'helpdesk_sla.escalation.warning_title', $parameters, $locale), 0, 255),
                'body' => trans('helpdesk_sla.escalation.body', [...$parameters, 'subject' => $ticket->subject], $locale),
            ]);
            $notified[] = (string) $user->id;
        }

        return ['notified_user_ids' => $notified];
    }

    /**
     * @return array<string, mixed>
     */
    private function reassign(Ticket $ticket, EscalationStep $step): array
    {
        if ($step->team_id === null || $ticket->assigned_team_id === $step->team_id || ! ServiceTeam::query()->where('tenant_id', $ticket->tenant_id)->whereKey($step->team_id)->exists()) {
            return ['skipped' => true];
        }

        $from = $ticket->assigned_team_id;
        $this->assignment->assign($ticket, $step->team_id, null, null, null, 'sla_escalation');

        return ['from_team_id' => $from, 'to_team_id' => $step->team_id];
    }

    /**
     * @return array<string, mixed>
     */
    private function raisePriority(Ticket $ticket, EscalationStep $step): array
    {
        $order = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

        if ($step->priority === null || ($order[$step->priority] ?? 0) <= ($order[(string) $ticket->priority] ?? 0)) {
            return ['skipped' => true];
        }

        $from = (string) $ticket->priority;
        $ticket->priority = $step->priority;
        $ticket->is_escalated = true;
        $ticket->escalation_level = (int) $ticket->escalation_level + 1;
        $ticket->save();

        return ['from_priority' => $from, 'to_priority' => $step->priority];
    }

    /**
     * Active chains of the tenant, keyed by the id of every SLA policy that points to them.
     *
     * @return array<string, EscalationChain>
     */
    private function chainsByPolicy(string $tenantId): array
    {
        $policies = SLAPolicy::query()->where('tenant_id', $tenantId)->whereNotNull('escalation_chain_id')->pluck('escalation_chain_id', 'id');

        if ($policies->isEmpty()) {
            return [];
        }

        $chains = EscalationChain::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('id', $policies->unique()->all())
            ->with('steps')
            ->get()
            ->keyBy('id');

        $byPolicy = [];

        foreach ($policies as $policyId => $chainId) {
            if (isset($chains[$chainId])) {
                $byPolicy[(string) $policyId] = $chains[$chainId];
            }
        }

        return $byPolicy;
    }
}
