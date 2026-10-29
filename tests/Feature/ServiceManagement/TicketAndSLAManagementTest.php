<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\ServiceManagement\Contracts\SLAManagementServiceInterface;
use Modules\ServiceManagement\Contracts\TicketServiceInterface;
use Modules\ServiceManagement\DTOs\AssignTicketDTO;
use Modules\ServiceManagement\DTOs\CreateTicketDTO;
use Modules\ServiceManagement\DTOs\EscalateTicketDTO;
use Modules\ServiceManagement\DTOs\ResolveTicketDTO;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\SLAPolicy;
use Modules\ServiceManagement\Models\Ticket;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);
    $this->user = User::factory()->create();
    $this->ticketService = app(TicketServiceInterface::class);
    $this->slaService = app(SLAManagementServiceInterface::class);
});

test('ticket creation calculates SLA response and resolution targets and dispatches event', function () {
    $sla = SLAPolicy::factory()->create([
        'tenant_id' => $this->tenant->id,
        'priority' => 'critical',
        'response_time_minutes' => 30,
        'resolution_time_minutes' => 180,
    ]);

    $dto = new CreateTicketDTO(
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        title: 'ERP database connection bottleneck',
        description: 'Connection pool exhausted on main application server',
        priority: 'critical',
        slaPolicyId: $sla->id,
        channel: 'portal'
    );

    $ticket = $this->ticketService->createTicket($dto, $this->user->id);

    expect($ticket)->toBeInstanceOf(Ticket::class)
        ->and($ticket->ticket_number)->toStartWith('TCK-')
        ->and($ticket->status)->toBe('open')
        ->and($ticket->priority)->toBe('critical')
        ->and($ticket->response_due_at)->not->toBeNull()
        ->and($ticket->resolution_due_at)->not->toBeNull();

    $this->assertDatabaseHas('esm_tickets', [
        'id' => $ticket->id,
        'tenant_id' => $this->tenant->id,
        'priority' => 'critical',
    ]);

    $this->assertDatabaseHas('transactional_outbox', [
        'tenant_id' => $this->tenant->id,
        'event_type' => 'esm.ticket.created',
        'aggregate_id' => $ticket->id,
    ]);
});

test('ticket lifecycle handles assignment, escalation, time logging, resolution, and closing', function () {
    $ticket = Ticket::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'status' => 'open',
    ]);

    $technician = ServiceTechnician::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
    ]);
    $technicianId = $technician->id;

    // 1. Assign
    $assignDto = new AssignTicketDTO(
        ticketId: $ticket->id,
        assignedToId: $technicianId,
        assignedBy: $this->user->id
    );
    $assignedTicket = $this->ticketService->assignTicket($assignDto, $this->user->id);
    expect($assignedTicket->status)->toBe('assigned')
        ->and($assignedTicket->assigned_to_id)->toBe($technicianId);

    // 2. Escalate
    $escalateDto = new EscalateTicketDTO(
        ticketId: $ticket->id,
        reason: 'SLA risk requires tier-2 specialist',
        escalationLevel: 2,
        escalatedBy: $this->user->id
    );
    $escalatedTicket = $this->ticketService->escalateTicket($escalateDto, $this->user->id);
    expect($escalatedTicket->status)->toBe('escalated')
        ->and($escalatedTicket->is_escalated)->toBeTrue()
        ->and($escalatedTicket->escalation_level)->toBe(2);

    // 3. Log Time
    $timeEntry = $this->ticketService->logTime(
        ticketId: $ticket->id,
        hours: 2.5,
        technicianId: $technicianId,
        workDescription: 'Configured connection pooler parameters',
        isBillable: true,
        hourlyRate: 80.00,
        actorUserId: $this->user->id
    );
    expect($timeEntry->hours_spent)->toBe(2.5)
        ->and((float) $timeEntry->total_cost)->toBe(87.50)
        ->and((float) $timeEntry->total_charge)->toBe(200.00);

    $refreshedTicket = $ticket->fresh();
    expect($refreshedTicket->actual_resolution_minutes)->toBe(150);

    // 4. Resolve
    $resolveDto = new ResolveTicketDTO(
        ticketId: $ticket->id,
        resolutionNotes: 'Updated max_connections to 300 and tuned pgBouncer pool.',
        rootCause: 'Connection leak in legacy reporting query',
        resolvedBy: $this->user->id
    );
    $resolvedTicket = $this->ticketService->resolveTicket($resolveDto, $this->user->id);
    expect($resolvedTicket->status)->toBe('resolved')
        ->and($resolvedTicket->resolved_at)->not->toBeNull();

    // 5. Close
    $closedTicket = $this->ticketService->closeTicket($ticket->id, $this->user->id, $this->user->id);
    expect($closedTicket->status)->toBe('closed')
        ->and($closedTicket->closed_at)->not->toBeNull();
});

test('SLA breach scan detects overdue tickets, updates breach status, and fires escalation event', function () {
    $overdueTicket = Ticket::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'status' => 'open',
        'resolution_due_at' => now()->subMinutes(15),
        'is_sla_breached' => false,
    ]);

    $validTicket = Ticket::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'status' => 'open',
        'resolution_due_at' => now()->addHours(5),
        'is_sla_breached' => false,
    ]);

    $breachedCount = $this->slaService->checkAndRecordBreaches();

    expect($breachedCount)->toBeGreaterThanOrEqual(1);

    expect($overdueTicket->fresh()->is_sla_breached)->toBeTrue();
    expect($validTicket->fresh()->is_sla_breached)->toBeFalse();

    $this->assertDatabaseHas('transactional_outbox', [
        'tenant_id' => $this->tenant->id,
        'event_type' => 'esm.ticket.escalated',
        'aggregate_id' => $overdueTicket->id,
    ]);
});
