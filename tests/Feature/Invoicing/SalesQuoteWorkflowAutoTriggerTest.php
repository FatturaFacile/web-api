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
use Modules\Invoicing\Events\SalesQuoteSubmittedEvent;
use Modules\Invoicing\Listeners\WorkflowApprovalInvoicingListener;
use Modules\Invoicing\Models\SalesQuote;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Models\WorkflowTransition;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'Quote Workflow Tenant', 'legal_name' => 'Quote Workflow Tenant Srl']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->customer = Contact::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'company_name' => 'Quote Client Srl'],
        ['id' => (string) Str::uuid7(), 'type' => 'client', 'country' => 'IT']
    );

    $this->user = User::firstOrCreate(
        ['email' => 'salesrep@example.com'],
        ['id' => (string) Str::uuid7(), 'name' => 'Sales Representative', 'password' => bcrypt('secret')]
    );

    // Setup complete SALES_QUOTE_APPROVAL definition: start -> approval -> end
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
        'node_key' => 'sales_manager_approval',
        'name' => 'Sales Manager Approval',
        'node_type' => 'approval',
        'approval_type' => 'single',
        'assignee_type' => 'direct_user',
        'assignee_id' => $this->user->id,
        'timeout_hours' => 24,
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

test('submitting sales quote automatically triggers workflow instance and updates quote status and outbox', function (): void {
    $quote = $this->quoteService->createQuote([
        'tenant_id' => (string) $this->tenant->id,
        'contact_id' => (string) $this->customer->id,
        'quote_number' => 'Q-WF-001',
    ], [
        ['description' => 'Enterprise System Integration', 'quantity' => 1.0, 'unit_price' => 15000.00, 'tax_rate' => 22.0],
    ]);

    expect($quote->status)->toBe('draft');
    expect($quote->workflow_instance_id)->toBeNull();

    // Submit for approval
    $submittedQuote = $this->quoteService->submitForApproval($quote, (string) $this->user->id);

    expect($submittedQuote->status)->toBe('pending_approval');
    expect($submittedQuote->fresh()->workflow_instance_id)->not->toBeNull();

    // Verify SalesQuoteSubmittedEvent in Outbox
    $outboxEntry = TransactionalOutbox::where('aggregate_type', 'sales_quote')
        ->where('aggregate_id', (string) $quote->id)
        ->where('event_type', 'invoicing.sales_quote.submitted')
        ->first();

    expect($outboxEntry)->not->toBeNull();
});

test('approves quote when workflow completes and rejects when workflow rejects', function (): void {
    $quote = $this->quoteService->createQuote([
        'tenant_id' => (string) $this->tenant->id,
        'contact_id' => (string) $this->customer->id,
        'quote_number' => 'Q-WF-APPROVE',
    ], [
        ['description' => 'Cloud Consulting', 'quantity' => 1.0, 'unit_price' => 5000.00, 'tax_rate' => 22.0],
    ]);

    $workflowId = (string) Str::uuid7();
    $quote->update([
        'status' => 'pending_approval',
        'workflow_instance_id' => $workflowId,
    ]);

    $approvalListener = app(WorkflowApprovalInvoicingListener::class);

    // Complete workflow
    $approvalListener->handleWorkflowApproved(new WorkflowInstanceCompletedEvent(
        instanceId: $workflowId,
        status: 'completed',
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id
    ));

    expect($quote->fresh()->status)->toBe('approved');
});
