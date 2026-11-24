<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\ServiceManagement\Contracts\SLAManagementServiceInterface;
use Modules\ServiceManagement\Contracts\TicketServiceInterface;
use Modules\ServiceManagement\DTOs\AssignTicketDTO;
use Modules\ServiceManagement\DTOs\CreateTicketDTO;
use Modules\ServiceManagement\DTOs\EscalateTicketDTO;
use Modules\ServiceManagement\DTOs\ResolveTicketDTO;
use Modules\ServiceManagement\Events\HelpdeskTicketChanged;
use Modules\ServiceManagement\Events\TicketAssignedEvent;
use Modules\ServiceManagement\Events\TicketClosedEvent;
use Modules\ServiceManagement\Events\TicketCreatedEvent;
use Modules\ServiceManagement\Events\TicketEscalatedEvent;
use Modules\ServiceManagement\Events\TicketResolvedEvent;
use Modules\ServiceManagement\Exceptions\HelpdeskRuleViolation;
use Modules\ServiceManagement\Models\KnowledgeArticle;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketTimeEntry;
use Modules\ServiceManagement\Services\Helpdesk\KnowledgeBaseService;
use Modules\ServiceManagement\Services\Helpdesk\TicketRoutingEngine;

final readonly class TicketService implements TicketServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private SLAManagementServiceInterface $slaService,
        private TicketRoutingEngine $routingEngine,
        private KnowledgeBaseService $knowledgeBase,
    ) {}

    public function createTicket(CreateTicketDTO $dto, ?string $actorUserId = null): Ticket
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $ticketNumber = 'TCK-'.date('Ymd').'-'.strtoupper(Str::random(6));

            $responseDue = $this->slaService->calculateDueDate($dto->slaPolicyId, $dto->priority, 'response');
            $resolutionDue = $this->slaService->calculateDueDate($dto->slaPolicyId, $dto->priority, 'resolution');

            $contactDetails = array_filter([
                'contact_email' => $dto->contactEmail,
                'contact_phone' => $dto->contactPhone,
                'sla_policy_id' => $dto->slaPolicyId,
            ], static fn (?string $value): bool => $value !== null && $value !== '');

            $ticket = Ticket::create([
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'ticket_number' => $ticketNumber,
                'subject' => $dto->title,
                'description' => $dto->description ?? $dto->title,
                'customer_id' => $dto->customerId ?: null,
                'priority' => $dto->priority,
                'impact' => $dto->impact,
                'urgency' => $dto->urgency,
                'tags' => $dto->tags === [] ? null : $dto->tags,
                'status_changed_at' => now(),
                'status' => $dto->status,
                'source' => $dto->channel,
                'category_id' => $dto->serviceCategoryId,
                'service_contract_id' => $dto->serviceContractId,
                'assigned_technician_id' => $dto->assignedToId,
                'assigned_team_id' => $dto->serviceTeamId,
                'response_due_at' => $responseDue,
                'resolution_due_at' => $resolutionDue,
                'metadata' => [...$dto->metadata, ...$contactDetails],
                'requester_user_id' => $dto->requesterUserId,
                'asset_id' => $dto->assetId,
            ]);

            if ($dto->applyRoutingRules && $ticket->assigned_technician_id === null) {
                $this->routingEngine->route($ticket, $actorUserId);
                $ticket->refresh();
            }

            $this->auditLedger->record(
                'TICKET_CREATED',
                $ticket,
                null,
                ['ticket_number' => $ticketNumber, 'priority' => $dto->priority],
                'MODULE_17_ESM'
            );

            $this->dispatcher->dispatch(new TicketCreatedEvent(
                ticketId: $ticket->id,
                ticketNumber: $ticket->ticket_number,
                title: $ticket->subject,
                priority: $ticket->priority,
                status: $ticket->status,
                customerId: $ticket->customer_id,
                tenantId: $ticket->tenant_id,
                companyId: $ticket->company_id ?? $ticket->tenant_id
            ));

            event(new HelpdeskTicketChanged($ticket->id, $ticket->tenant_id, HelpdeskTicketChanged::Created, $actorUserId));

            return $ticket;
        });
    }

    public function assignTicket(AssignTicketDTO $dto, ?string $actorUserId = null): Ticket
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $ticket = Ticket::query()->whereKey($dto->ticketId)->lockForUpdate()->firstOrFail();

            if ($ticket->merged_into_ticket_id !== null) {
                throw new HelpdeskRuleViolation("Ticket {$ticket->ticket_number} was merged into another ticket.");
            }

            $oldValues = [
                'assigned_technician_id' => $ticket->assigned_technician_id,
                'assigned_team_id' => $ticket->assigned_team_id,
                'status' => $ticket->status,
            ];

            $ticket->update([
                'assigned_technician_id' => $dto->assignedToId,
                'assigned_team_id' => $dto->serviceTeamId ?? $ticket->assigned_team_id,
                'status' => $ticket->status === 'open' ? 'assigned' : $ticket->status,
            ]);

            $this->auditLedger->record(
                'TICKET_ASSIGNED',
                $ticket,
                $oldValues,
                ['assigned_to_id' => $dto->assignedToId, 'assigned_by' => $dto->assignedBy],
                'MODULE_17_ESM'
            );

            $this->dispatcher->dispatch(new TicketAssignedEvent(
                ticketId: $ticket->id,
                ticketNumber: $ticket->ticket_number,
                assignedToId: $dto->assignedToId,
                serviceTeamId: $ticket->assigned_team_id,
                tenantId: $ticket->tenant_id,
                companyId: $ticket->company_id ?? $ticket->tenant_id
            ));

            event(new HelpdeskTicketChanged($ticket->id, $ticket->tenant_id, HelpdeskTicketChanged::Assigned, $actorUserId, [
                'team_id' => $ticket->assigned_team_id,
                'technician_id' => $dto->assignedToId,
            ]));

            return $ticket;
        });
    }

    public function escalateTicket(EscalateTicketDTO $dto, ?string $actorUserId = null): Ticket
    {
        return DB::transaction(function () use ($dto) {
            $ticket = Ticket::findOrFail($dto->ticketId);

            $oldValues = [
                'escalation_level' => $ticket->escalation_level,
                'is_escalated' => $ticket->is_escalated,
                'status' => $ticket->status,
            ];

            $ticket->update([
                'escalation_level' => $dto->escalationLevel,
                'is_escalated' => true,
                'status' => 'escalated',
            ]);

            $this->auditLedger->record(
                'TICKET_ESCALATED',
                $ticket,
                $oldValues,
                ['reason' => $dto->reason, 'level' => $dto->escalationLevel],
                'MODULE_17_ESM'
            );

            $this->dispatcher->dispatch(new TicketEscalatedEvent(
                ticketId: $ticket->id,
                ticketNumber: $ticket->ticket_number,
                reason: $dto->reason,
                escalationLevel: $dto->escalationLevel,
                tenantId: $ticket->tenant_id,
                companyId: $ticket->company_id ?? $ticket->tenant_id
            ));

            return $ticket;
        });
    }

    public function resolveTicket(ResolveTicketDTO $dto, ?string $actorUserId = null): Ticket
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $ticket = Ticket::query()->whereKey($dto->ticketId)->lockForUpdate()->firstOrFail();

            if (in_array($ticket->status, ['resolved', 'closed'], true)) {
                throw new HelpdeskRuleViolation("Ticket {$ticket->ticket_number} is already {$ticket->status}.");
            }

            if ($ticket->merged_into_ticket_id !== null) {
                throw new HelpdeskRuleViolation("Ticket {$ticket->ticket_number} was merged into another ticket.");
            }

            $oldValues = ['status' => $ticket->status];

            $ticket->update([
                'status' => 'resolved',
                'resolution_summary' => $dto->resolutionNotes,
                'root_cause' => $dto->rootCause,
                'knowledge_article_id' => $dto->knowledgeArticleId,
                'resolved_at' => now(),
                'status_changed_at' => now(),
                'sla_paused_at' => null,
            ]);

            if ($dto->knowledgeArticleId !== null) {
                $article = KnowledgeArticle::query()->where('tenant_id', $ticket->tenant_id)->find($dto->knowledgeArticleId)
                    ?? throw new HelpdeskRuleViolation('The knowledge base article belongs to another company.');
                $this->knowledgeBase->link($ticket, $article, $actorUserId ?? $dto->resolvedBy);
            }

            event(new HelpdeskTicketChanged($ticket->id, $ticket->tenant_id, HelpdeskTicketChanged::StatusChanged, $actorUserId, ['from' => $oldValues['status'], 'to' => 'resolved']));

            $this->auditLedger->record(
                'TICKET_RESOLVED',
                $ticket,
                $oldValues,
                ['resolution_notes' => $dto->resolutionNotes, 'root_cause' => $dto->rootCause],
                'MODULE_17_ESM'
            );

            $this->dispatcher->dispatch(new TicketResolvedEvent(
                ticketId: $ticket->id,
                ticketNumber: $ticket->ticket_number,
                resolutionNotes: $dto->resolutionNotes,
                rootCause: $dto->rootCause,
                tenantId: $ticket->tenant_id,
                companyId: $ticket->company_id ?? $ticket->tenant_id
            ));

            return $ticket;
        });
    }

    public function closeTicket(string $ticketId, string $closedBy, ?string $actorUserId = null): Ticket
    {
        return DB::transaction(function () use ($ticketId, $closedBy, $actorUserId) {
            $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->firstOrFail();

            if ($ticket->status === 'closed') {
                return $ticket;
            }

            $oldValues = ['status' => $ticket->status];

            $ticket->update([
                'status' => 'closed',
                'closed_at' => now(),
                'resolved_at' => $ticket->resolved_at ?? now(),
                'status_changed_at' => now(),
                'sla_paused_at' => null,
            ]);

            event(new HelpdeskTicketChanged($ticket->id, $ticket->tenant_id, HelpdeskTicketChanged::StatusChanged, $actorUserId, ['from' => $oldValues['status'], 'to' => 'closed']));

            $this->auditLedger->record(
                'TICKET_CLOSED',
                $ticket,
                $oldValues,
                ['closed_by' => $closedBy],
                'MODULE_17_ESM'
            );

            $this->dispatcher->dispatch(new TicketClosedEvent(
                ticketId: $ticket->id,
                ticketNumber: $ticket->ticket_number,
                closedBy: $closedBy,
                tenantId: $ticket->tenant_id,
                companyId: $ticket->company_id ?? $ticket->tenant_id
            ));

            return $ticket;
        });
    }

    public function logTime(
        string $ticketId,
        string|int|float $hours,
        ?string $technicianId = null,
        ?string $workDescription = null,
        bool $isBillable = true,
        string|int|float|null $hourlyRate = null,
        ?string $actorUserId = null
    ): TicketTimeEntry {
        return DB::transaction(function () use ($ticketId, $hours, $technicianId, $workDescription, $isBillable, $hourlyRate) {
            $ticket = Ticket::findOrFail($ticketId);

            $technicianId ??= $ticket->assigned_technician_id;

            if ($technicianId === null) {
                throw new InvalidArgumentException('Time can be logged only for a technician: state one or assign the ticket.');
            }

            $technician = ServiceTechnician::where('tenant_id', $ticket->tenant_id)->findOrFail($technicianId);
            $hours = ServiceAmounts::text($hours);
            $hourlyRate = ServiceAmounts::money($hourlyRate ?? $technician->hourly_billable_rate);
            $hourlyCost = ServiceAmounts::money($technician->hourly_cost_rate);
            $laborCost = ServiceAmounts::lineTotal($hours, $hourlyRate);

            $entry = TicketTimeEntry::create([
                'tenant_id' => $ticket->tenant_id,
                'company_id' => $ticket->company_id,
                'ticket_id' => $ticket->id,
                'technician_id' => $technicianId,
                'hours' => $hours,
                'hourly_rate' => $hourlyRate,
                'hourly_cost' => $hourlyCost,
                'total_cost' => ServiceAmounts::lineTotal($hours, $hourlyCost),
                'total_charge' => $laborCost,
                'is_billable' => $isBillable,
                'work_date' => now()->toDateString(),
                'description' => $workDescription,
            ]);

            $ticket->actual_resolution_minutes = $ticket->actual_resolution_minutes + (int) bcround(bcmul($hours, '60', 4), 0);
            $ticket->save();

            $this->auditLedger->record(
                'TICKET_TIME_LOGGED',
                $entry,
                null,
                ['ticket_id' => $ticketId, 'hours' => $hours, 'cost' => $laborCost],
                'MODULE_17_ESM'
            );

            return $entry;
        });
    }
}
