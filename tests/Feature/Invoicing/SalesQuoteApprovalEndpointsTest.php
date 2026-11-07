<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Invoicing\Contracts\SalesQuoteServiceInterface;
use Modules\Invoicing\Models\SalesQuote;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Models\WorkflowTransition;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-05-04 09:00:00');
    $this->tenant = Tenant::create(['name' => 'Preventivi Srl', 'legal_name' => 'Preventivi Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Cliente SpA', 'country' => 'IT']);
    $this->manager = quoteApprovalMember($this->tenant, 'sales_manager');
});

function quoteApprovalMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role), 'email' => Str::random(10).'@example.it', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function quoteApprovalApi(User $user): mixed
{
    Sanctum::actingAs($user);

    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

function quoteForApproval(float $unitPrice = 1000.0): SalesQuote
{
    return app(SalesQuoteServiceInterface::class)->createQuote(
        ['tenant_id' => (string) test()->tenant->id, 'contact_id' => (string) test()->contact->id],
        [['description' => 'Impianto', 'quantity' => 1, 'unit_price' => $unitPrice, 'tax_rate' => 22]]
    );
}

function quoteApprovalWorkflow(Tenant $tenant, User $approver): void
{
    $definition = WorkflowDefinition::create(['tenant_id' => $tenant->id, 'code' => 'SALES_QUOTE_APPROVAL', 'name' => 'Approvazione preventivi', 'target_entity_type' => SalesQuote::class, 'version' => 1, 'status' => 'published', 'is_active' => true]);
    $nodes = [];

    foreach ([['start', 'start', []], ['manager', 'approval', ['approval_type' => 'single', 'assignee_type' => 'direct_user', 'assignee_id' => $approver->id, 'timeout_hours' => 24]], ['end', 'end', []]] as [$key, $type, $extra]) {
        $nodes[$key] = WorkflowNode::create(['tenant_id' => $tenant->id, 'workflow_definition_id' => $definition->id, 'node_key' => $key, 'name' => ucfirst($key), 'node_type' => $type, ...$extra]);
    }

    WorkflowTransition::create(['tenant_id' => $tenant->id, 'workflow_definition_id' => $definition->id, 'from_node_id' => $nodes['start']->id, 'to_node_id' => $nodes['manager']->id, 'action_trigger' => 'start']);
    WorkflowTransition::create(['tenant_id' => $tenant->id, 'workflow_definition_id' => $definition->id, 'from_node_id' => $nodes['manager']->id, 'to_node_id' => $nodes['end']->id, 'action_trigger' => 'approve']);
}

test('without a workflow a submitted quote is approved directly by a sales manager and the decision is audited', function (): void {
    $quote = quoteForApproval();

    $submitted = quoteApprovalApi(quoteApprovalMember($this->tenant, 'sales'))->postJson("/api/v1/invoicing/quotes/{$quote->id}/submit-for-approval")->assertOk();

    expect($submitted->json('approval_mode'))->toBe('manual')
        ->and($submitted->json('workflow_instance_id'))->toBeNull()
        ->and($submitted->json('quote.status'))->toBe('pending_approval');

    quoteApprovalApi(quoteApprovalMember($this->tenant, 'sales'))->postJson("/api/v1/invoicing/quotes/{$quote->id}/approve")->assertForbidden();

    $approved = quoteApprovalApi($this->manager)->postJson("/api/v1/invoicing/quotes/{$quote->id}/approve")->assertOk();

    expect($approved->json('status'))->toBe('approved')
        ->and($quote->fresh()->approved_by)->toBe((string) $this->manager->id)
        ->and(AuditLedgerEntry::query()->where('action', 'SALES_QUOTE_APPROVED_WITHOUT_WORKFLOW')->where('resource_id', $quote->id)->exists())->toBeTrue();

    quoteApprovalApi($this->manager)->postJson("/api/v1/invoicing/quotes/{$quote->id}/approve")->assertUnprocessable();
});

test('an owner rejects a submitted quote with a reason, and a draft cannot be decided before it is submitted', function (): void {
    $draft = quoteForApproval();
    $owner = quoteApprovalMember($this->tenant, 'owner');

    quoteApprovalApi($owner)->postJson("/api/v1/invoicing/quotes/{$draft->id}/reject", ['reason' => 'Prezzo'])->assertUnprocessable();

    quoteApprovalApi($owner)->postJson("/api/v1/invoicing/quotes/{$draft->id}/submit-for-approval")->assertOk();
    $rejected = quoteApprovalApi($owner)->postJson("/api/v1/invoicing/quotes/{$draft->id}/reject", ['reason' => 'Margine troppo basso'])->assertOk();

    expect($rejected->json('status'))->toBe('rejected')
        ->and($draft->fresh()->notes)->toContain('Margine troppo basso');

    quoteApprovalApi($owner)->postJson("/api/v1/invoicing/quotes/{$draft->id}/submit-for-approval")->assertUnprocessable();
});

test('a quote waiting for approval cannot be accepted or converted', function (): void {
    $quote = quoteForApproval();
    quoteApprovalApi($this->manager)->postJson("/api/v1/invoicing/quotes/{$quote->id}/submit-for-approval")->assertOk();

    quoteApprovalApi($this->manager)->postJson("/api/v1/invoicing/quotes/{$quote->id}/accept")->assertUnprocessable();
    quoteApprovalApi($this->manager)->postJson("/api/v1/invoicing/quotes/{$quote->id}/convert")->assertUnprocessable();
    quoteApprovalApi($this->manager)->postJson("/api/v1/sales/quotes/{$quote->id}/convert-to-order")->assertUnprocessable();

    expect($quote->fresh()->status)->toBe('pending_approval');
});

test('with an active workflow the submission starts an instance and the direct decision is refused', function (): void {
    quoteApprovalWorkflow($this->tenant, $this->manager);
    $quote = quoteForApproval(30000.0);

    $submitted = quoteApprovalApi($this->manager)->postJson("/api/v1/invoicing/quotes/{$quote->id}/submit-for-approval")->assertOk();

    expect($submitted->json('approval_mode'))->toBe('workflow')
        ->and($submitted->json('workflow_instance_id'))->toBe((string) $quote->fresh()->workflow_instance_id)
        ->and($submitted->json('workflow_instance_id'))->not->toBeNull();

    quoteApprovalApi(quoteApprovalMember($this->tenant, 'owner'))->postJson("/api/v1/invoicing/quotes/{$quote->id}/approve")->assertUnprocessable();
    quoteApprovalApi($this->manager)->postJson("/api/v1/invoicing/quotes/{$quote->id}/reject")->assertUnprocessable();

    expect($quote->fresh()->status)->toBe('pending_approval');
});

test('a quote of another company is not found', function (): void {
    $quote = quoteForApproval();
    $other = Tenant::create(['name' => 'Altra Srl', 'legal_name' => 'Altra Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $stranger = quoteApprovalMember($other, 'owner');

    Sanctum::actingAs($stranger);
    $this->withHeader('X-Tenant-Id', $other->id)->postJson("/api/v1/invoicing/quotes/{$quote->id}/approve")->assertNotFound();
});
