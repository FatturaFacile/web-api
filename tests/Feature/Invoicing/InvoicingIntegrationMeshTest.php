<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Support\Str;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Contracts\SalesQuoteServiceInterface;
use Modules\Invoicing\Listeners\ProjectsBillingEventListener;
use Modules\Invoicing\Listeners\WorkflowApprovalInvoicingListener;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Projects\Events\ProjectBillingReadyEvent;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'Integration Mesh Test Tenant', 'legal_name' => 'Integration Mesh Test Tenant Srl']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->customer = Contact::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'company_name' => 'Mesh Partner Srl'],
        ['id' => (string) Str::uuid7(), 'type' => 'client', 'country' => 'IT']
    );

    $this->quoteService = app(SalesQuoteServiceInterface::class);
    $this->orderService = app(SalesOrderServiceInterface::class);
    $this->invoicingService = app(InvoicingServiceInterface::class);
});

test('handles ProjectBillingReadyEvent from Module 15 and creates milestone billing invoice', function (): void {
    $tenantId = (string) $this->tenant->id;
    $customerId = (string) $this->customer->id;
    $projectId = (string) Str::uuid7();
    $milestoneId = (string) Str::uuid7();

    $event = new ProjectBillingReadyEvent(
        billingEventId: (string) Str::uuid7(),
        projectId: $projectId,
        contactId: $customerId,
        billingAmount: 25000.00,
        billingType: 'milestone',
        milestoneId: $milestoneId,
        tenantId: $tenantId,
        companyId: $tenantId
    );

    $listener = app(ProjectsBillingEventListener::class);
    $listener->handle($event);

    $invoice = SalesInvoice::where('tenant_id', $tenantId)
        ->where('project_milestone_id', $milestoneId)
        ->first();

    expect($invoice)->not->toBeNull();
    expect((float) $invoice->total_net)->toBe(25000.00);
    expect($invoice->notes)->toContain('Project Billing - milestone');
});

test('handles WorkflowInstanceCompletedEvent and WorkflowInstanceRejectedEvent from Module 21', function (): void {
    $workflowInstanceId = (string) Str::uuid7();

    $quote = $this->quoteService->createQuote([
        'tenant_id' => (string) $this->tenant->id,
        'contact_id' => (string) $this->customer->id,
        'quote_number' => 'Q-WORKFLOW-TEST',
    ], [
        ['description' => 'Service item', 'quantity' => 1.0, 'unit_price' => 5000.00, 'tax_rate' => 22.0],
    ]);

    $quote->update(['workflow_instance_id' => $workflowInstanceId]);
    expect($quote->status)->toBe('draft');

    $workflowApprovedEvent = new WorkflowInstanceCompletedEvent(
        instanceId: $workflowInstanceId,
        status: 'approved',
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id
    );

    $listener = app(WorkflowApprovalInvoicingListener::class);
    $listener->handleWorkflowApproved($workflowApprovedEvent);

    expect($quote->fresh()->status)->toBe('approved');

    // Test rejection on another quote
    $rejectWorkflowId = (string) Str::uuid7();
    $quote2 = $this->quoteService->createQuote([
        'tenant_id' => (string) $this->tenant->id,
        'contact_id' => (string) $this->customer->id,
        'quote_number' => 'Q-WORKFLOW-REJECT',
    ], [
        ['description' => 'Service item', 'quantity' => 1.0, 'unit_price' => 5000.00, 'tax_rate' => 22.0],
    ]);
    $quote2->update(['workflow_instance_id' => $rejectWorkflowId]);

    $workflowRejectedEvent = new WorkflowInstanceRejectedEvent(
        instanceId: $rejectWorkflowId,
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id
    );

    $listener->handleWorkflowRejected($workflowRejectedEvent);

    expect($quote2->fresh()->status)->toBe('rejected');
});

test('writes invoicing domain events to transactional outbox', function (): void {
    $quote = $this->quoteService->createQuote([
        'tenant_id' => (string) $this->tenant->id,
        'contact_id' => (string) $this->customer->id,
        'quote_number' => 'Q-OUTBOX-TEST',
    ], [
        ['description' => 'Outbox item', 'quantity' => 1.0, 'unit_price' => 1000.00, 'tax_rate' => 22.0],
    ]);

    $outboxEntry = TransactionalOutbox::where('aggregate_type', 'sales_quote')
        ->where('aggregate_id', (string) $quote->id)
        ->first();

    expect($outboxEntry)->not->toBeNull();
    expect($outboxEntry->event_type)->toBe('sales.quote.created');
});
