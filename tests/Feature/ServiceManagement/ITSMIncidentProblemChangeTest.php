<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\ServiceManagement\Contracts\IncidentManagementServiceInterface;
use Modules\ServiceManagement\Contracts\ProblemAndChangeServiceInterface;
use Modules\ServiceManagement\DTOs\CreateChangeRequestDTO;
use Modules\ServiceManagement\DTOs\CreateIncidentDTO;
use Modules\ServiceManagement\DTOs\CreateProblemDTO;
use Modules\ServiceManagement\Models\ChangeRequest;
use Modules\ServiceManagement\Models\Incident;
use Modules\ServiceManagement\Models\Problem;
use Modules\ServiceManagement\Models\Ticket;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);
    $this->user = User::factory()->create();
    $this->incidentService = app(IncidentManagementServiceInterface::class);
    $this->itsmService = app(ProblemAndChangeServiceInterface::class);
});

test('incident lifecycle handles detection, ticket linking, resolution, and closing', function () {
    $dto = new CreateIncidentDTO(
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        title: 'Core ERP Authentication Service Outage',
        description: 'Multiple users receiving 500 error on SSO authentication',
        severity: 'critical',
        impact: 'extensive',
        urgency: 'immediate',
        reportedById: $this->user->id
    );

    $incident = $this->incidentService->createIncident($dto, $this->user->id);

    expect($incident)->toBeInstanceOf(Incident::class)
        ->and($incident->incident_number)->toStartWith('INC-')
        ->and($incident->status)->toBe('investigating')
        ->and($incident->is_major_incident)->toBeTrue();

    $this->assertDatabaseHas('transactional_outbox', [
        'tenant_id' => $this->tenant->id,
        'event_type' => 'esm.incident.created',
        'aggregate_id' => $incident->id,
    ]);

    // Link to ticket
    $ticket = Ticket::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
    ]);
    $this->incidentService->linkToTicket($incident->id, $ticket->id);
    expect($incident->fresh()->ticket_id)->toBe($ticket->id);

    // Resolve
    $resolved = $this->incidentService->resolveIncident(
        incidentId: $incident->id,
        summary: 'OAuth token validation service restarted on secondary cluster node.',
        actorUserId: $this->user->id
    );
    expect($resolved->status)->toBe('resolved')
        ->and($resolved->resolved_at)->not->toBeNull();

    $this->assertDatabaseHas('transactional_outbox', [
        'tenant_id' => $this->tenant->id,
        'event_type' => 'esm.incident.resolved',
        'aggregate_id' => $incident->id,
    ]);

    // Close
    $closed = $this->incidentService->closeIncident($incident->id, $this->user->id);
    expect($closed->status)->toBe('closed')
        ->and($closed->closed_at)->not->toBeNull();
});

test('problem management tracks recurring issues, known errors, and permanent resolution', function () {
    $dto = new CreateProblemDTO(
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        title: 'Recurring Memory Leak in PDF Generation Worker',
        description: 'Worker memory grows unbounded after 500 document exports',
        priority: 'high',
        rootCauseAnalysis: 'Unclosed DOM parser handles retained in memory',
        workaround: 'Recycle worker pods every 2 hours'
    );

    $problem = $this->itsmService->createProblem($dto, $this->user->id);

    expect($problem)->toBeInstanceOf(Problem::class)
        ->and($problem->problem_number)->toStartWith('PRB-')
        ->and($problem->status)->toBe('under_investigation');

    $this->assertDatabaseHas('transactional_outbox', [
        'tenant_id' => $this->tenant->id,
        'event_type' => 'esm.problem.created',
        'aggregate_id' => $problem->id,
    ]);

    // Mark as resolved with permanent solution
    $resolved = $this->itsmService->resolveProblem(
        problemId: $problem->id,
        workaround: 'Recycle worker pods every 2 hours',
        permanentSolution: 'Upgraded PDF generator library to v4.2 with proper garbage collection',
        actorUserId: $this->user->id
    );

    expect($resolved->status)->toBe('resolved')
        ->and($resolved->permanent_solution)->toContain('Upgraded PDF generator library');

    $this->assertDatabaseHas('transactional_outbox', [
        'tenant_id' => $this->tenant->id,
        'event_type' => 'esm.problem.resolved',
        'aggregate_id' => $problem->id,
    ]);
});

test('change management manages RFC lifecycle from submission to CAB approval and implementation', function () {
    $dto = new CreateChangeRequestDTO(
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        title: 'Deploy PostgreSQL 17 Indexing Optimization Patch',
        description: 'Add missing partial indexes on accounting journal ledger',
        changeType: 'normal',
        riskLevel: 'medium',
        priority: 'high',
        rolloutPlan: 'Apply migration via background non-blocking CONCURRENTLY statement',
        rollbackPlan: 'DROP INDEX CONCURRENTLY if query regression detected',
        testPlan: 'Run benchmark load tests in staging'
    );

    $change = $this->itsmService->createChangeRequest($dto, $this->user->id);

    expect($change)->toBeInstanceOf(ChangeRequest::class)
        ->and($change->change_number)->toStartWith('CR-')
        ->and($change->status)->toBe('submitted');

    $this->assertDatabaseHas('transactional_outbox', [
        'tenant_id' => $this->tenant->id,
        'event_type' => 'esm.change_request.created',
        'aggregate_id' => $change->id,
    ]);

    // CAB Approval
    $approved = $this->itsmService->approveChangeRequest($change->id, $this->user->id, $this->user->id);
    expect($approved->status)->toBe('approved')
        ->and($approved->cab_decision)->toBe('approved');

    $this->assertDatabaseHas('transactional_outbox', [
        'tenant_id' => $this->tenant->id,
        'event_type' => 'esm.change_request.approved',
        'aggregate_id' => $change->id,
    ]);

    // Implementation
    $implemented = $this->itsmService->implementChangeRequest($change->id, $this->user->id);
    expect($implemented->status)->toBe('implemented')
        ->and($implemented->implemented_at)->not->toBeNull();
});
