<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Support\Str;
use Modules\Invoicing\Contracts\SalesQuoteServiceInterface;
use Modules\Invoicing\Listeners\WorkflowApprovalInvoicingListener;
use Modules\Invoicing\Models\SalesQuote;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Models\WorkflowTransition;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'Quote Trigger Test Tenant', 'legal_name' => 'Quote Trigger Test Tenant Srl']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->customer = Contact::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'company_name' => 'Quote Client Direct Srl'],
        ['id' => (string) Str::uuid7(), 'type' => 'client', 'country' => 'IT']
    );

    $this->user = User::firstOrCreate(
        ['email' => 'salesexec@example.com'],
        ['id' => (string) Str::uuid7(), 'name' => 'Sales Executive', 'password' => bcrypt('secret')]
    );

    $this->definition = WorkflowDefinition::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'code' => 'SALES_QUOTE_APPROVAL',
        'name' => 'Sales Quote Approval Workflow',
        'target_entity_type' => SalesQuote::class,
        'version' => 1,
        'status' => 'published',
        'is_active' => true,
    ]);

    $startNode = WorkflowNode::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'workflow_definition_id' => $this->definition->id,
        'node_key' => 'start',
        'name' => 'Start Process',
        'node_type' => 'start',
    ]);

    $approvalNode = WorkflowNode::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'workflow_definition_id' => $this->definition->id,
        'node_key' => 'director_approval',
        'name' => 'Sales Director Approval',
        'node_type' => 'approval',
        'approval_type' => 'single',
        'assignee_type' => 'direct_user',
        'assignee_id' => $this->user->id,
        'timeout_hours' => 48,
    ]);

    $endNode = WorkflowNode::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'workflow_definition_id' => $this->definition->id,
        'node_key' => 'end',
        'name' => 'Process Complete',
        'node_type' => 'end',
    ]);

    WorkflowTransition::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'workflow_definition_id' => $this->definition->id,
        'from_node_id' => $startNode->id,
        'to_node_id' => $approvalNode->id,
        'action_trigger' => 'start',
    ]);

    WorkflowTransition::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'workflow_definition_id' => $this->definition->id,
        'from_node_id' => $approvalNode->id,
        'to_node_id' => $endNode->id,
        'action_trigger' => 'approve',
    ]);

    $this->quoteService = app(SalesQuoteServiceInterface::class);
});

test('QuoteWorkflowTriggerTest: quote submission automatically triggers SALES_QUOTE_APPROVAL workflow', function (): void {
    $quote = $this->quoteService->createQuote([
        'tenant_id' => (string) $this->tenant->id,
        'contact_id' => (string) $this->customer->id,
        'quote_number' => 'Q-AUTO-001',
    ], [
        ['description' => 'ERP Core Implementation', 'quantity' => 1.0, 'unit_price' => 15000.00, 'tax_rate' => 22.0],
    ]);

    expect($quote->status)->toBe('draft');
    expect($quote->workflow_instance_id)->toBeNull();

    $submitted = $this->quoteService->submitForApproval($quote, (string) $this->user->id);

    expect($submitted->status)->toBe('pending_approval');
    expect($submitted->fresh()->workflow_instance_id)->not->toBeNull();

    // Verify outbox entry
    $outbox = TransactionalOutbox::where('aggregate_type', 'sales_quote')
        ->where('aggregate_id', (string) $quote->id)
        ->where('event_type', 'invoicing.sales_quote.submitted')
        ->first();

    expect($outbox)->not->toBeNull();
});

test('QuoteApprovalWorkflowTest: workflow completion approves sales quote', function (): void {
    $quote = $this->quoteService->createQuote([
        'tenant_id' => (string) $this->tenant->id,
        'contact_id' => (string) $this->customer->id,
        'quote_number' => 'Q-AUTO-APPROVE',
    ], [
        ['description' => 'Consulting Hours', 'quantity' => 10.0, 'unit_price' => 150.00, 'tax_rate' => 22.0],
    ]);

    $workflowId = (string) Str::uuid7();
    $quote->update([
        'status' => 'pending_approval',
        'workflow_instance_id' => $workflowId,
    ]);

    $listener = app(WorkflowApprovalInvoicingListener::class);
    $listener->handleWorkflowApproved(new WorkflowInstanceCompletedEvent(
        instanceId: $workflowId,
        status: 'completed',
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id
    ));

    expect($quote->fresh()->status)->toBe('approved');
});

test('QuoteRejectionWorkflowTest: workflow rejection marks sales quote as rejected', function (): void {
    $quote = $this->quoteService->createQuote([
        'tenant_id' => (string) $this->tenant->id,
        'contact_id' => (string) $this->customer->id,
        'quote_number' => 'Q-AUTO-REJECT',
    ], [
        ['description' => 'Discounted Service Package', 'quantity' => 1.0, 'unit_price' => 2000.00, 'tax_rate' => 22.0],
    ]);

    $workflowId = (string) Str::uuid7();
    $quote->update([
        'status' => 'pending_approval',
        'workflow_instance_id' => $workflowId,
    ]);

    $listener = app(WorkflowApprovalInvoicingListener::class);
    $listener->handleWorkflowRejected(new WorkflowInstanceRejectedEvent(
        instanceId: $workflowId,
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id
    ));

    expect($quote->fresh()->status)->toBe('rejected');
});
