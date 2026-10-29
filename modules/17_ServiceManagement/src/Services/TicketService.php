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
use Modules\ServiceManagement\Events\TicketAssignedEvent;
use Modules\ServiceManagement\Events\TicketClosedEvent;
use Modules\ServiceManagement\Events\TicketCreatedEvent;
use Modules\ServiceManagement\Events\TicketEscalatedEvent;
use Modules\ServiceManagement\Events\TicketResolvedEvent;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketTimeEntry;

final readonly class TicketService implements TicketServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private SLAManagementServiceInterface $slaService
    ) {}

    public function createTicket(CreateTicketDTO $dto, ?string $actorUserId = null): Ticket
    {
        return DB::transaction(function () use ($dto) {
            $ticketNumber = 'TCK-'.date('Ymd').'-'.strtoupper(Str::random(6));

            $responseDue = $this->slaService->calculateDueDate($dto->slaPolicyId, $dto->priority, 'response');
            $resolutionDue = $this->slaService->calculateDueDate($dto->slaPolicyId, $dto->priority, 'resolution');

            $customerId = $dto->customerId ?: (string) Str::uuid7();

            $ticket = Ticket::create([
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'ticket_number' => $ticketNumber,
                'subject' => $dto->title,
                'description' => $dto->description ?? $dto->title,
                'customer_id' => $customerId,
                'priority' => $dto->priority,
                'status' => 'open',
                'source' => $dto->channel,
                'category_id' => $dto->serviceCategoryId,
                'service_contract_id' => $dto->serviceContractId,
                'assigned_technician_id' => $dto->assignedToId,
                'assigned_team_id' => $dto->serviceTeamId,
                'response_due_at' => $responseDue,
                'resolution_due_at' => $resolutionDue,
                'metadata' => $dto->metadata,
            ]);

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
                companyId: $ticket->company_id
            ));

            return $ticket;
        });
    }

    public function assignTicket(AssignTicketDTO $dto, ?string $actorUserId = null): Ticket
    {
        return DB::transaction(function () use ($dto) {
            $ticket = Ticket::findOrFail($dto->ticketId);

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
                companyId: $ticket->company_id
            ));

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
                companyId: $ticket->company_id
            ));

            return $ticket;
        });
    }

    public function resolveTicket(ResolveTicketDTO $dto, ?string $actorUserId = null): Ticket
    {
        return DB::transaction(function () use ($dto) {
            $ticket = Ticket::findOrFail($dto->ticketId);

            if (in_array($ticket->status, ['resolved', 'closed'], true)) {
                throw new InvalidArgumentException("Ticket {$ticket->ticket_number} is already {$ticket->status}.");
            }

            $oldValues = ['status' => $ticket->status];

            $ticket->update([
                'status' => 'resolved',
                'resolution_summary' => $dto->resolutionNotes,
                'root_cause' => $dto->rootCause,
                'knowledge_article_id' => $dto->knowledgeArticleId,
                'resolved_at' => now(),
            ]);

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
                companyId: $ticket->company_id
            ));

            return $ticket;
        });
    }

    public function closeTicket(string $ticketId, string $closedBy, ?string $actorUserId = null): Ticket
    {
        return DB::transaction(function () use ($ticketId, $closedBy) {
            $ticket = Ticket::findOrFail($ticketId);

            if ($ticket->status === 'closed') {
                return $ticket;
            }

            $oldValues = ['status' => $ticket->status];

            $ticket->update([
                'status' => 'closed',
                'closed_at' => now(),
            ]);

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
                companyId: $ticket->company_id
            ));

            return $ticket;
        });
    }

    public function logTime(
        string $ticketId,
        float $hours,
        ?string $technicianId = null,
        ?string $workDescription = null,
        bool $isBillable = true,
        float $hourlyRate = 75.0,
        ?string $actorUserId = null
    ): TicketTimeEntry {
        return DB::transaction(function () use ($ticketId, $hours, $technicianId, $workDescription, $isBillable, $hourlyRate) {
            $ticket = Ticket::findOrFail($ticketId);

            if ($technicianId === null) {
                $tech = ServiceTechnician::firstOrCreate(
                    ['tenant_id' => $ticket->tenant_id, 'name' => 'Support Engineer'],
                    [
                        'company_id' => $ticket->company_id,
                        'email' => 'tech.'.Str::random(5).'@support.local',
                        'hourly_cost_rate' => 35.00,
                        'hourly_billing_rate' => $hourlyRate,
                    ]
                );
                $technicianId = $tech->id;
            }

            $laborCost = round($hours * $hourlyRate, 2);

            $entry = TicketTimeEntry::create([
                'tenant_id' => $ticket->tenant_id,
                'company_id' => $ticket->company_id,
                'ticket_id' => $ticket->id,
                'technician_id' => $technicianId,
                'hours' => $hours,
                'hourly_rate' => $hourlyRate,
                'hourly_cost' => 35.00,
                'total_cost' => round($hours * 35.00, 2),
                'total_charge' => $laborCost,
                'is_billable' => $isBillable,
                'work_date' => now()->toDateString(),
                'description' => $workDescription,
            ]);

            $ticket->actual_resolution_minutes = $ticket->actual_resolution_minutes + (int) round($hours * 60);
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
