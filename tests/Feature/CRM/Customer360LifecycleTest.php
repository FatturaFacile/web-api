<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\ContactAddress;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;
use Modules\ServiceManagement\Models\ContractHourTransaction;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\ServiceManagement\Models\WorkOrderItem;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::create(['name' => 'Agent', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);

    $this->customer = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'company_name' => 'Rossi SA']);
    ContactAddress::create(['contact_id' => $this->customer->id, 'type' => 'site', 'street' => 'Bahnhofstrasse', 'house_number' => '1', 'postal_code' => '8001', 'city' => 'Zurich', 'country' => 'CH', 'is_default' => true]);

    $this->account = Account::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'contact_id' => (string) $this->customer->id,
        'account_number' => 'ACC-360',
        'name' => 'Rossi SA',
        'tier' => 'enterprise',
        'status' => 'active',
        'customer_health_index' => 70.00,
        'churn_risk' => 'medium',
    ]);
});

function lifecycleTicket(array $overrides = []): Ticket
{
    return Ticket::factory()->create(array_merge([
        'tenant_id' => test()->tenant->id,
        'customer_id' => test()->customer->id,
        'status' => 'open',
        'priority' => 'high',
        'subject' => 'Chiller offline',
        'description' => 'The chiller stopped overnight',
        'resolution_due_at' => now()->addDay(),
    ], $overrides));
}

test('escalating a ticket creates a work order that inherits customer, contract, SLA deadline and address', function (): void {
    $contract = ServiceContract::create([
        'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'contract_number' => 'MC-1', 'title' => 'Maintenance',
        'start_date' => '2026-01-01', 'status' => 'active', 'coverage_type' => 'parts_and_labor', 'currency' => 'CHF', 'included_hours' => '10.00',
    ]);
    $ticket = lifecycleTicket(['service_contract_id' => $contract->id]);

    $response = $this->postJson("/api/v1/esm/tickets/{$ticket->id}/escalate-to-field-service", ['diagnostic_notes' => 'Compressor tripped']);

    $response->assertCreated()->assertJsonPath('data.created', true)->assertJsonPath('data.work_order.ticket_id', $ticket->id);
    $workOrder = WorkOrder::query()->where('ticket_id', $ticket->id)->firstOrFail();
    expect($workOrder->customer_id)->toBe((string) $this->customer->id)
        ->and($workOrder->service_contract_id)->toBe((string) $contract->id)
        ->and($workOrder->description)->toContain('Compressor tripped')
        ->and($workOrder->scheduled_end?->toDateTimeString())->toBe($ticket->resolution_due_at->toDateTimeString())
        ->and((string) json_encode($workOrder->location))->toContain('Bahnhofstrasse 1')
        ->and($ticket->refresh()->is_escalated)->toBeTrue();
});

test('escalating twice returns the open work order instead of a second one', function (): void {
    $ticket = lifecycleTicket();

    $this->postJson("/api/v1/esm/tickets/{$ticket->id}/escalate-to-field-service")->assertCreated();
    $this->postJson("/api/v1/esm/tickets/{$ticket->id}/escalate-to-field-service")->assertOk()->assertJsonPath('data.created', false);

    expect(WorkOrder::query()->where('ticket_id', $ticket->id)->count())->toBe(1);
});

test('a resolved ticket cannot be escalated', function (): void {
    $ticket = lifecycleTicket(['status' => 'resolved']);

    $this->postJson("/api/v1/esm/tickets/{$ticket->id}/escalate-to-field-service")->assertUnprocessable()->assertJsonValidationErrors('ticket');
});

test('full lifecycle: escalate, complete with contract coverage, ticket resolved and hours deducted, timeline lists every step', function (): void {
    $stage = OpportunityStage::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'code' => 'closed_won'],
        ['id' => (string) Str::uuid7(), 'name' => 'Closed Won', 'sequence' => 50, 'default_probability' => 100.0, 'is_won' => true, 'is_active' => true],
    );
    Opportunity::create([
        'id' => (string) Str::uuid7(), 'tenant_id' => (string) $this->tenant->id, 'account_id' => (string) $this->account->id, 'stage_id' => (string) $stage->id,
        'opportunity_number' => 'OPP-360', 'name' => 'Chiller service deal', 'amount' => 5000, 'expected_close_date' => now()->toDateString(), 'status' => 'won',
    ]);
    Activity::create(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $this->tenant->id, 'subject' => 'Kick-off call', 'type' => 'call', 'status' => 'completed', 'related_type' => 'account', 'related_id' => (string) $this->account->id]);

    $contract = ServiceContract::create([
        'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'contract_number' => 'MC-2', 'title' => 'Maintenance',
        'start_date' => '2026-01-01', 'status' => 'active', 'coverage_type' => 'parts_and_labor', 'currency' => 'CHF', 'included_hours' => '10.00',
    ]);
    $ticket = lifecycleTicket(['service_contract_id' => $contract->id]);

    $this->postJson("/api/v1/esm/tickets/{$ticket->id}/escalate-to-field-service")->assertCreated();
    $workOrder = WorkOrder::query()->where('ticket_id', $ticket->id)->firstOrFail();
    $workOrder->update(['status' => 'in_progress', 'total_labor_hours' => '2.00']);

    $response = $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/complete-and-bill", [
        'tax_rate' => '8.1',
        'resolution_summary' => 'Replaced the compressor relay',
        'signed_by_customer_name' => 'Mario Rossi',
        'customer_signature_url' => 'data:image/png;base64,AAAA',
    ]);

    $response->assertOk()->assertJsonPath('data.ticket_status', 'resolved')->assertJsonPath('data.settlement', 'contract_deduction')->assertJsonPath('data.invoice_id', null);
    expect($ticket->refresh()->status)->toBe('resolved')
        ->and(abs((float) ContractHourTransaction::query()->where('work_order_id', $workOrder->id)->sum('hours')))->toBe(2.0);

    $summary = $this->getJson("/api/v1/crm/customers/{$this->account->id}/360")->assertOk();
    $summary->assertJsonPath('data.kpis.interventions_completed', 1)
        ->assertJsonPath('data.kpis.open_tickets', 0)
        ->assertJsonPath('data.kpis.active_contracts', 1)
        ->assertJsonPath('data.kpis.contract_coverage', 'covered')
        ->assertJsonPath('data.kpis.open_pipeline_value', '0.00')
        ->assertJsonPath('data.health.churn_risk', 'medium');

    $types = collect($this->getJson("/api/v1/crm/customers/{$this->account->id}/timeline")->assertOk()->json('data'))->pluck('type')->unique()->sort()->values()->all();
    expect($types)->toBe(['activity', 'contract', 'opportunity', 'ticket', 'work_order']);
});

test('completing an uncovered billable intervention drafts one invoice and a replay does not duplicate it', function (): void {
    $ticket = lifecycleTicket();
    $this->postJson("/api/v1/esm/tickets/{$ticket->id}/escalate-to-field-service")->assertCreated();
    $workOrder = WorkOrder::query()->where('ticket_id', $ticket->id)->firstOrFail();
    WorkOrderItem::create([
        'tenant_id' => $this->tenant->id, 'work_order_id' => $workOrder->id, 'item_type' => 'labor', 'description' => 'On-site labor',
        'quantity' => '2', 'unit_cost' => '50', 'total_cost' => '100', 'unit_price' => '120', 'total_price' => '240', 'is_billable' => true,
    ]);
    $workOrder->update(['status' => 'in_progress', 'total_billable_amount' => '240.00', 'is_billable' => true]);

    $payload = ['tax_rate' => '8.1', 'resolution_summary' => 'Fixed', 'signed_by_customer_name' => 'Mario Rossi'];
    $first = $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/complete-and-bill", $payload)->assertOk();
    $second = $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/complete-and-bill", $payload)->assertOk();

    expect($first->json('data.settlement'))->toBe('invoice_draft')
        ->and($first->json('data.invoice_id'))->not->toBeNull()
        ->and($second->json('data.invoice_id'))->toBe($first->json('data.invoice_id'))
        ->and(WorkOrder::query()->whereKey($workOrder->id)->whereNotNull('invoice_id')->count())->toBe(1);
});

test('complete-and-bill requires the VAT rate', function (): void {
    $workOrder = WorkOrder::create(['tenant_id' => $this->tenant->id, 'work_order_number' => 'WO-'.Str::random(6), 'title' => 'Repair', 'work_type' => 'corrective', 'priority' => 'high', 'status' => 'in_progress', 'customer_id' => $this->customer->id, 'currency' => 'CHF']);

    $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/complete-and-bill", [])->assertUnprocessable()->assertJsonValidationErrors('tax_rate');
});

test('timeline filters by type and rejects unknown types', function (): void {
    lifecycleTicket();
    Activity::create(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $this->tenant->id, 'subject' => 'Visit', 'type' => 'meeting', 'status' => 'completed', 'related_type' => 'account', 'related_id' => (string) $this->account->id]);

    $only = $this->getJson("/api/v1/crm/customers/{$this->account->id}/timeline?types[]=ticket")->assertOk()->json('data');
    expect(collect($only)->pluck('type')->unique()->all())->toBe(['ticket']);

    $this->getJson("/api/v1/crm/customers/{$this->account->id}/timeline?types[]=nonsense")->assertUnprocessable()->assertJsonValidationErrors('types.0');
});

test('tenant isolation: another tenant cannot read the 360, the timeline, escalate or complete', function (): void {
    $ticket = lifecycleTicket();
    $workOrder = WorkOrder::create(['tenant_id' => $this->tenant->id, 'work_order_number' => 'WO-'.Str::random(6), 'title' => 'Repair', 'work_type' => 'corrective', 'priority' => 'high', 'status' => 'in_progress', 'customer_id' => $this->customer->id, 'currency' => 'CHF']);

    $intruder = User::create(['name' => 'Intruder', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $intruder->tenants()->attach($this->other->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    app(TenantContext::class)->setTenant($this->other);
    Sanctum::actingAs($intruder);
    $this->withHeader('X-Tenant-Id', $this->other->id);

    $this->getJson("/api/v1/crm/customers/{$this->account->id}/360")->assertNotFound();
    $this->getJson("/api/v1/crm/customers/{$this->account->id}/timeline")->assertNotFound();
    $this->postJson("/api/v1/esm/tickets/{$ticket->id}/escalate-to-field-service")->assertNotFound();
    $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/complete-and-bill", ['tax_rate' => '8.1'])->assertNotFound();
});

test('a sales role can read the 360 but cannot escalate or complete', function (): void {
    $ticket = lifecycleTicket();
    $viewer = User::create(['name' => 'Viewer', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $viewer->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'sales']);
    Sanctum::actingAs($viewer);

    $this->getJson("/api/v1/crm/customers/{$this->account->id}/360")->assertOk();
    $this->postJson("/api/v1/esm/tickets/{$ticket->id}/escalate-to-field-service")->assertForbidden();
});

test('an account without a contact returns an empty 360 instead of failing', function (): void {
    $account = Account::create(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $this->tenant->id, 'account_number' => 'ACC-NC', 'name' => 'Walk-in', 'tier' => 'standard', 'status' => 'active', 'customer_health_index' => 50.00, 'churn_risk' => 'low']);

    $this->getJson("/api/v1/crm/customers/{$account->id}/360")->assertOk()
        ->assertJsonPath('data.kpis.open_tickets', 0)
        ->assertJsonPath('data.kpis.contract_coverage', 'none')
        ->assertJsonPath('data.kpis.lifetime_value', '0.00');
});

test('a completed intervention and a resolved ticket refresh the customer health profile', function (): void {
    $ticket = lifecycleTicket(['response_breached_at' => now()->subDay()]);
    $this->postJson("/api/v1/esm/tickets/{$ticket->id}/escalate-to-field-service")->assertCreated();
    $workOrder = WorkOrder::query()->where('ticket_id', $ticket->id)->firstOrFail();
    $workOrder->update(['status' => 'in_progress']);

    expect(CustomerHealthProfile::query()->where('account_id', $this->account->id)->exists())->toBeFalse();

    $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/complete-and-bill", ['tax_rate' => '8.1', 'signed_by_customer_name' => 'Mario Rossi'])->assertOk();

    $profile = CustomerHealthProfile::query()->where('account_id', $this->account->id)->first();
    expect($profile)->not->toBeNull()
        ->and($profile->evaluation_metrics['sla_breaches_90d'])->toBe(1);
});
