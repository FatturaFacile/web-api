<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Inventory\Events\ProjectMaterialConsumptionEvent;
use Modules\Procurement\Events\PurchaseOrderApprovedEvent;
use Modules\Projects\Events\ProjectCostReadyEvent;
use Modules\Projects\Events\ProjectCreatedEvent;
use Modules\Projects\Events\ProjectRevenueReadyEvent;
use Modules\Projects\Listeners\InventoryIntegrationListener;
use Modules\Projects\Listeners\ProcurementIntegrationListener;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Models\ProjectFinancialSummary;
use Modules\Projects\Models\ProjectTask;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    Gate::before(fn () => true);

    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Project Audit & EventBus Tenant',
        'legal_name' => 'Enterprise Project Engineering SpA',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::first() ?? User::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'name' => 'Audit & Compliance Officer',
        'email' => 'compliance@projects-audit.test',
        'password' => bcrypt('password123'),
    ]);

    DB::table('tenant_user')->updateOrInsert(
        ['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id],
        [
            'id' => (string) Str::uuid7(),
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );

    $this->contact = Contact::where('tenant_id', $this->tenant->id)->first() ?? Contact::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'type' => 'customer',
        'country' => 'IT',
        'company_name' => 'Infrastructure Holding SpA',
        'email' => 'info@holding-audit.it',
        'vat_number' => 'IT01928374650',
    ]);

    $this->project = Project::factory()->active()->create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->contact->id,
        'contract_value' => 250000.00,
    ]);

    $this->summary = ProjectFinancialSummary::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'project_id' => $this->project->id,
        'total_contract_revenue' => 250000.00,
        'budget_at_completion_bac' => 180000.00,
        'total_actual_cost' => 0.00,
        'committed_po_cost' => 0.00,
        'last_recalculated_at' => now(),
    ]);
});

test('transactional outbox reliably records project domain events for asynchronous bus relay', function (): void {
    $dispatcher = app(EventDispatcher::class);

    $event = new ProjectCreatedEvent(
        projectId: $this->project->id,
        projectNumber: $this->project->project_number,
        projectName: $this->project->name,
        projectType: $this->project->project_type,
        contractValue: 250000.00,
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id
    );

    $dispatcher->publish($event);

    $outboxRecord = DB::table('transactional_outbox')
        ->where('event_type', 'project.created')
        ->where('aggregate_id', $this->project->id)
        ->first();

    expect($outboxRecord)->not->toBeNull()
        ->and($outboxRecord->status)->toBe('pending')
        ->and($outboxRecord->aggregate_type)->toBe('project')
        ->and($outboxRecord->payload_json)->toContain($this->project->project_number);
});

test('immutable audit ledger records project lifecycle actions with SHA-256 provenance', function (): void {
    $auditLedger = app(AuditLedgerInterface::class);

    $auditLedger->record(
        action: 'contract_amendment',
        model: $this->project,
        payloadBefore: ['contract_value' => 250000.00],
        payloadAfter: ['contract_value' => 285000.00],
        moduleCode: 'projects'
    );

    $amendmentEntry = DB::table('enterprise_audit_ledger')
        ->where('resource_id', $this->project->id)
        ->where('action', 'CONTRACT_AMENDMENT')
        ->first();

    expect($amendmentEntry)->not->toBeNull()
        ->and($amendmentEntry->record_hash)->not->toBeEmpty();
});

test('inventory integration listener records material direct cost on consumption event', function (): void {
    $listener = app(InventoryIntegrationListener::class);

    $task = ProjectTask::factory()->create([
        'tenant_id' => $this->tenant->id,
        'project_id' => $this->project->id,
    ]);

    $event = new ProjectMaterialConsumptionEvent(
        projectId: $this->project->id,
        taskId: $task->id,
        stockItemId: (string) Str::uuid7(),
        quantity: 50.0,
        unitCost: 120.00,
        totalCost: 6000.00,
        warehouseId: (string) Str::uuid7(),
        binId: null,
        companyId: $this->tenant->id,
        tenantId: $this->tenant->id
    );

    $listener->handleMaterialConsumption($event);

    $costEntry = ProjectCostEntry::where('project_id', $this->project->id)
        ->where('cost_category', 'material')
        ->where('cost_source_id', $event->getAggregateId())
        ->first();

    expect($costEntry)->not->toBeNull()
        ->and((float) $costEntry->total_cost)->toBe(6000.00)
        ->and($costEntry->cost_source_type)->toBe('StockMovement');

    $this->summary->refresh();
    expect((float) $this->summary->total_actual_cost)->toBe(6000.00)
        ->and((float) $this->summary->actual_material_cost)->toBe(6000.00);
});

test('procurement integration listener updates committed PO encumbrance on approval', function (): void {
    $listener = app(ProcurementIntegrationListener::class);

    $event = new PurchaseOrderApprovedEvent(
        purchaseOrderId: (string) Str::uuid7(),
        poNumber: 'PO-SUB-2027-01',
        supplierId: (string) Str::uuid7(),
        totalAmount: 45000.00,
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        projectId: $this->project->id
    );

    $listener->handlePurchaseOrderApproved($event);

    $this->summary->refresh();
    expect((float) $this->summary->committed_po_cost)->toBe(45000.00);
});

test('accounting readiness events are properly constructed and emitted', function (): void {
    Event::fake([
        ProjectCostReadyEvent::class,
        ProjectRevenueReadyEvent::class,
    ]);

    $costReady = new ProjectCostReadyEvent(
        costEntryId: (string) Str::uuid7(),
        projectId: $this->project->id,
        costCategory: 'material',
        totalCost: 8500.00,
        debitAccountCode: '3100',
        creditAccountCode: '1400',
        incurredDate: now()->toDateString(),
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id
    );

    $revenueReady = new ProjectRevenueReadyEvent(
        recognitionId: (string) Str::uuid7(),
        projectId: $this->project->id,
        recognizedAmount: 25000.00,
        debitAccountCode: '1100',
        creditAccountCode: '4100',
        accountingPeriod: now()->format('Y-m'),
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id
    );

    event($costReady);
    event($revenueReady);

    Event::assertDispatched(ProjectCostReadyEvent::class);
    Event::assertDispatched(ProjectRevenueReadyEvent::class);
});
