<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Contracts\SLAManagementServiceInterface;
use Modules\ServiceManagement\Enums\TicketStatus;
use Modules\ServiceManagement\Events\HelpdeskTicketChanged;
use Modules\ServiceManagement\Events\TicketClosedEvent;
use Modules\ServiceManagement\Events\TicketResolvedEvent;
use Modules\ServiceManagement\Exceptions\HelpdeskRuleViolation;
use Modules\ServiceManagement\Models\HelpdeskSetting;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketMessage;
use Modules\ServiceManagement\Models\TicketWatcher;

/**
 * Lifecycle of a helpdesk ticket: field updates, status transitions with reopen window and SLA pause, merge, split and
 * watchers. Each operation locks the ticket rows it changes and checks their state inside the same transaction.
 */
final readonly class HelpdeskTicketService
{
    /**
     * Fields a client may change directly.
     *
     * @var list<string>
     */
    private const array EditableFields = ['subject', 'description', 'impact', 'urgency', 'priority', 'category_id', 'customer_id', 'service_contract_id', 'asset_id', 'type'];

    public function __construct(
        private TicketActivityLog $activityLog,
        private TicketMessageWriter $messageWriter,
        private TicketSlaClock $slaClock,
        private TicketAssignmentService $assignment,
        private TicketPriorityMatrix $priorityMatrix,
        private SLAManagementServiceInterface $slaService,
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated fields; tags replace the list, assigned_team_id and assigned_technician_id reassign
     */
    public function update(Ticket $ticket, array $data, ?string $actorUserId = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $data, $actorUserId): Ticket {
            $ticket = $this->lock($ticket->id);
            $this->assertNotMerged($ticket);

            $changes = [];

            foreach (self::EditableFields as $field) {
                if (array_key_exists($field, $data) && $ticket->getAttribute($field) !== $data[$field]) {
                    $changes[$field] = ['from' => $ticket->getAttribute($field), 'to' => $data[$field]];
                    $ticket->setAttribute($field, $data[$field]);
                }
            }

            if (! array_key_exists('priority', $data) && (array_key_exists('impact', $data) || array_key_exists('urgency', $data))) {
                $priority = $this->priorityMatrix->priorityFor($ticket->impact, $ticket->urgency);

                if ($priority !== null && $priority !== $ticket->priority) {
                    $changes['priority'] = ['from' => $ticket->priority, 'to' => $priority];
                    $ticket->priority = $priority;
                }
            }

            if (array_key_exists('tags', $data)) {
                $tags = $this->normalizeTags((array) $data['tags']);

                if ($tags !== ($ticket->tags ?? [])) {
                    $changes['tags'] = ['from' => $ticket->tags ?? [], 'to' => $tags];
                    $ticket->tags = $tags;
                }
            }

            $metadata = is_array($ticket->metadata) ? $ticket->metadata : [];

            foreach (['contact_email', 'contact_phone', 'contact_name'] as $field) {
                if (array_key_exists($field, $data) && ($metadata[$field] ?? null) !== $data[$field]) {
                    $changes[$field] = ['from' => $metadata[$field] ?? null, 'to' => $data[$field]];
                    $metadata[$field] = $data[$field];
                }
            }

            $ticket->metadata = $metadata;
            $ticket->save();

            if ($changes !== []) {
                $this->activityLog->record($ticket, 'updated', null, null, ['changes' => $changes], $actorUserId);

                if (isset($changes['priority'])) {
                    $this->activityLog->record($ticket, 'priority_changed', (string) $changes['priority']['from'], (string) $changes['priority']['to'], [], $actorUserId);
                }

                $this->auditLedger->record('TICKET_UPDATED', $ticket, null, ['changes' => array_keys($changes)], 'MODULE_17_ESM');
            }

            if (array_key_exists('assigned_team_id', $data) || array_key_exists('assigned_technician_id', $data)) {
                $this->assignment->assign(
                    $ticket,
                    array_key_exists('assigned_team_id', $data) ? $data['assigned_team_id'] : $ticket->assigned_team_id,
                    array_key_exists('assigned_technician_id', $data) ? $data['assigned_technician_id'] : $ticket->assigned_technician_id,
                    $actorUserId
                );
            }

            return $ticket->refresh();
        });
    }

    public function changeStatus(Ticket $ticket, TicketStatus $target, ?string $actorUserId = null, ?string $note = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $target, $actorUserId, $note): Ticket {
            $ticket = $this->lock($ticket->id);
            $this->applyStatus($ticket, $target, $actorUserId, $note);

            return $ticket->refresh();
        });
    }

    /**
     * Moves a locked ticket to a status. Checks the transition and the reopen window, stops or restarts the SLA clock
     * and stamps resolution and closure.
     */
    public function applyStatus(Ticket $ticket, TicketStatus $target, ?string $actorUserId = null, ?string $note = null): void
    {
        $this->assertNotMerged($ticket);
        $current = $ticket->statusEnum();

        if (! $current->canTransitionTo($target)) {
            throw new HelpdeskRuleViolation("A ticket cannot move from {$current->value} to {$target->value}.");
        }

        if ($current->isReopenTo($target)) {
            $this->assertWithinReopenWindow($ticket);
            $ticket->reopened_count = (int) $ticket->reopened_count + 1;
            $ticket->resolved_at = null;
            $ticket->closed_at = null;
        }

        if ($current === TicketStatus::PendingCustomer) {
            $this->slaClock->resume($ticket);
        }

        if ($target === TicketStatus::PendingCustomer) {
            $this->slaClock->pause($ticket);
        }

        if ($target === TicketStatus::Resolved) {
            $ticket->resolved_at = now();
        }

        if ($target === TicketStatus::Closed) {
            $ticket->closed_at = now();
            $ticket->resolved_at ??= now();
        }

        $ticket->status = $target->value;
        $ticket->status_changed_at = now();
        $ticket->save();

        $this->activityLog->record($ticket, $current->isReopenTo($target) ? 'reopened' : 'status_changed', $current->value, $target->value, $note === null ? [] : ['note' => $note], $actorUserId);
        $this->auditLedger->record('TICKET_STATUS_CHANGED', $ticket, ['status' => $current->value], ['status' => $target->value], 'MODULE_17_ESM');

        if ($target === TicketStatus::Resolved) {
            $this->dispatcher->dispatch(new TicketResolvedEvent(
                ticketId: $ticket->id,
                ticketNumber: $ticket->ticket_number,
                resolutionNotes: $note ?? $ticket->resolution_summary,
                rootCause: $ticket->root_cause,
                tenantId: $ticket->tenant_id,
                companyId: $ticket->company_id ?? $ticket->tenant_id
            ));
        }

        if ($target === TicketStatus::Closed) {
            $this->dispatcher->dispatch(new TicketClosedEvent(
                ticketId: $ticket->id,
                ticketNumber: $ticket->ticket_number,
                closedBy: $actorUserId ?? 'system',
                tenantId: $ticket->tenant_id,
                companyId: $ticket->company_id ?? $ticket->tenant_id
            ));
        }

        event(new HelpdeskTicketChanged($ticket->id, $ticket->tenant_id, HelpdeskTicketChanged::StatusChanged, $actorUserId, [
            'from' => $current->value,
            'to' => $target->value,
        ]));
    }

    /**
     * Merges a ticket into another. The merged ticket is closed and points to the target; its conversation stays
     * attached to it and is shown in the target's thread, its watchers follow the target.
     */
    public function merge(Ticket $source, Ticket $target, ?string $actorUserId = null): Ticket
    {
        if ($source->id === $target->id) {
            throw new HelpdeskRuleViolation('A ticket cannot be merged into itself.');
        }

        return DB::transaction(function () use ($source, $target, $actorUserId): Ticket {
            // Both rows are locked in a fixed order, so two merges of the same pair in opposite directions cannot deadlock.
            $locked = Ticket::query()->whereIn('id', [$source->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $locked[$source->id] ?? throw new HelpdeskRuleViolation('Ticket not found.');
            $target = $locked[$target->id] ?? throw new HelpdeskRuleViolation('Ticket not found.');

            if ($source->tenant_id !== $target->tenant_id) {
                throw new HelpdeskRuleViolation('Tickets of different companies cannot be merged.');
            }

            $this->assertNotMerged($source);
            $this->assertNotMerged($target);

            if (! $target->statusEnum()->isActive()) {
                throw new HelpdeskRuleViolation('The target ticket is resolved or closed: reopen it first.');
            }

            $previousStatus = $source->status;
            $source->merged_into_ticket_id = $target->id;
            $source->status = TicketStatus::Closed->value;
            $source->closed_at = now();
            $source->status_changed_at = now();
            $source->save();

            $targetWatchers = TicketWatcher::query()->where('ticket_id', $target->id)->pluck('user_id')->all();

            foreach (TicketWatcher::query()->where('ticket_id', $source->id)->get() as $watcher) {
                if (! in_array($watcher->user_id, $targetWatchers, true)) {
                    TicketWatcher::query()->create(['tenant_id' => $target->tenant_id, 'ticket_id' => $target->id, 'user_id' => $watcher->user_id]);
                }
            }

            $target->tags = $this->normalizeTags([...($target->tags ?? []), ...($source->tags ?? [])]);
            $target->save();

            $this->activityLog->record($source, 'merged_into', $previousStatus, $target->ticket_number, ['target_ticket_id' => $target->id], $actorUserId);
            $this->activityLog->record($target, 'merged_from', null, $source->ticket_number, ['source_ticket_id' => $source->id], $actorUserId);
            $this->messageWriter->systemNote($target, "Ticket {$source->ticket_number} ({$source->subject}) was merged into this ticket.", $actorUserId);
            $this->messageWriter->systemNote($source, "This ticket was merged into {$target->ticket_number}.", $actorUserId);
            $this->auditLedger->record('TICKET_MERGED', $source, ['status' => $previousStatus], ['merged_into' => $target->id], 'MODULE_17_ESM');

            return $target->refresh();
        });
    }

    /**
     * Splits messages of a ticket into a new ticket for the same requester. The chosen messages move to the new ticket.
     *
     * @param  list<string>  $messageIds
     */
    public function split(Ticket $source, string $subject, array $messageIds, ?string $actorUserId = null): Ticket
    {
        return DB::transaction(function () use ($source, $subject, $messageIds, $actorUserId): Ticket {
            $source = $this->lock($source->id);
            $this->assertNotMerged($source);

            $messages = TicketMessage::query()->where('ticket_id', $source->id)->whereIn('id', $messageIds)->orderBy('created_at')->get();

            if (count($messageIds) !== $messages->count()) {
                throw new HelpdeskRuleViolation('Every message to split must belong to the ticket.');
            }

            $metadata = is_array($source->metadata) ? $source->metadata : [];
            $policyId = $metadata['sla_policy_id'] ?? null;

            $child = Ticket::query()->create([
                'tenant_id' => $source->tenant_id,
                'company_id' => $source->company_id,
                'ticket_number' => self::newTicketNumber(),
                'subject' => $subject,
                'description' => (string) ($messages->first()?->body ?? $source->description),
                'customer_id' => $source->customer_id,
                'contact_id' => $source->contact_id,
                'requester_user_id' => $source->requester_user_id,
                'priority' => $source->priority,
                'impact' => $source->impact,
                'urgency' => $source->urgency,
                'status' => TicketStatus::Open->value,
                'source' => $source->source,
                'category_id' => $source->category_id,
                'service_contract_id' => $source->service_contract_id,
                'assigned_team_id' => $source->assigned_team_id,
                'assigned_technician_id' => $source->assigned_technician_id,
                'tags' => $source->tags,
                'split_from_ticket_id' => $source->id,
                'response_due_at' => $this->slaService->calculateDueDate(is_string($policyId) ? $policyId : null, (string) $source->priority, 'response'),
                'resolution_due_at' => $this->slaService->calculateDueDate(is_string($policyId) ? $policyId : null, (string) $source->priority, 'resolution'),
                'status_changed_at' => now(),
                'metadata' => array_intersect_key($metadata, array_flip(['contact_email', 'contact_phone', 'contact_name', 'sla_policy_id', 'mailbox_id'])),
            ]);

            foreach ($messages as $message) {
                $message->ticket_id = $child->id;
                $message->save();
                $message->attachments()->update(['ticket_id' => $child->id]);
            }

            $this->activityLog->record($source, 'split_to', null, $child->ticket_number, ['child_ticket_id' => $child->id, 'message_ids' => $messageIds], $actorUserId);
            $this->activityLog->record($child, 'split_from', null, $source->ticket_number, ['source_ticket_id' => $source->id], $actorUserId);
            $this->messageWriter->systemNote($source, "Messages were split into the new ticket {$child->ticket_number}.", $actorUserId);
            $this->auditLedger->record('TICKET_SPLIT', $child, null, ['split_from' => $source->id], 'MODULE_17_ESM');

            event(new HelpdeskTicketChanged($child->id, $child->tenant_id, HelpdeskTicketChanged::Created, $actorUserId, ['split_from' => $source->id]));

            return $child;
        });
    }

    /**
     * Hands the ticket to an agent of a team chosen by round robin or least load. The ticket row and the team row are
     * locked, so two concurrent requests never pick on stale state.
     */
    public function autoAssign(Ticket $ticket, ?string $teamId, string $strategy, ?string $actorUserId = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $teamId, $strategy, $actorUserId): Ticket {
            $ticket = $this->lock($ticket->id);
            $this->assertNotMerged($ticket);
            $teamId ??= $ticket->assigned_team_id ?? throw new HelpdeskRuleViolation('The ticket has no team to choose an agent from.');

            $this->assignment->assign($ticket, $teamId, null, $actorUserId, $strategy, 'auto');

            if ($ticket->assigned_technician_id === null) {
                throw new HelpdeskRuleViolation('No agent of the team is available.');
            }

            return $ticket->refresh();
        });
    }

    public function addWatcher(Ticket $ticket, string $userId, ?string $actorUserId = null): TicketWatcher
    {
        return DB::transaction(function () use ($ticket, $userId, $actorUserId): TicketWatcher {
            $ticket = $this->lock($ticket->id);
            $existing = TicketWatcher::query()->where('ticket_id', $ticket->id)->where('user_id', $userId)->first();

            if ($existing !== null) {
                return $existing;
            }

            $this->activityLog->record($ticket, 'watcher_added', null, $userId, [], $actorUserId);

            return TicketWatcher::query()->create(['tenant_id' => $ticket->tenant_id, 'ticket_id' => $ticket->id, 'user_id' => $userId]);
        });
    }

    public function removeWatcher(Ticket $ticket, string $userId, ?string $actorUserId = null): void
    {
        DB::transaction(function () use ($ticket, $userId, $actorUserId): void {
            $ticket = $this->lock($ticket->id);
            $removed = TicketWatcher::query()->where('ticket_id', $ticket->id)->where('user_id', $userId)->forceDelete();

            if ($removed > 0) {
                $this->activityLog->record($ticket, 'watcher_removed', $userId, null, [], $actorUserId);
            }
        });
    }

    public function lock(string $ticketId): Ticket
    {
        return Ticket::query()->whereKey($ticketId)->lockForUpdate()->firstOrFail();
    }

    public function assertWithinReopenWindow(Ticket $ticket): void
    {
        $days = HelpdeskSetting::current($ticket->tenant_id)->reopen_window_days;
        $since = $ticket->resolved_at ?? $ticket->closed_at;

        if ($since !== null && CarbonImmutable::instance($since)->addDays($days)->isPast()) {
            throw new HelpdeskRuleViolation("The ticket was resolved more than {$days} days ago and can no longer be reopened.");
        }
    }

    public function isWithinReopenWindow(Ticket $ticket): bool
    {
        try {
            $this->assertWithinReopenWindow($ticket);

            return true;
        } catch (HelpdeskRuleViolation) {
            return false;
        }
    }

    public function assertNotMerged(Ticket $ticket): void
    {
        if ($ticket->merged_into_ticket_id !== null) {
            throw new HelpdeskRuleViolation("Ticket {$ticket->ticket_number} was merged into another ticket.");
        }
    }

    public static function newTicketNumber(): string
    {
        return 'TCK-'.date('Ymd').'-'.strtoupper(Str::random(6));
    }

    /**
     * @param  array<int, mixed>  $tags
     * @return list<string>
     */
    public function normalizeTags(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            $tag = mb_strtolower(trim((string) $tag));

            if ($tag !== '' && ! in_array($tag, $normalized, true)) {
                $normalized[] = mb_substr($tag, 0, 50);
            }
        }

        sort($normalized);

        return $normalized;
    }
}
