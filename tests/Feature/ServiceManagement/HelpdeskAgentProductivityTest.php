<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceManagement\Models\CannedResponse;
use Modules\ServiceManagement\Models\Macro;
use Modules\ServiceManagement\Models\SavedView;
use Modules\ServiceManagement\Models\ServiceTeam;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketMessage;

uses(RefreshDatabase::class);

function helpdeskProductivityMember(Tenant $tenant, string $role = 'admin', string $name = 'Agent'): User
{
    $user = User::create(['name' => $name, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->foreignTicket = Ticket::factory()->create(['tenant_id' => $this->other->id]);
    $this->customer = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'company_name' => 'Villa Rosa', 'email' => 'villa@example.com']);
    $this->ticket = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'open', 'priority' => 'low', 'subject' => 'Boiler', 'customer_id' => $this->customer->id]);
    $this->agent = helpdeskProductivityMember($this->tenant, 'technician', 'Giulia');
    Sanctum::actingAs($this->agent);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
});

test('a canned response is rendered with the placeholders of the ticket and the agent', function (): void {
    $response = CannedResponse::factory()->create(['tenant_id' => $this->tenant->id, 'body' => 'Dear {{requester.name}}, {{agent.name}} handles {{ticket.number}} ({{ticket.subject}}). {{unknown.key}}']);

    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/canned-responses/{$response->id}/render")
        ->assertOk()
        ->assertJsonPath('data.body', "Dear Villa Rosa, Giulia handles {$this->ticket->ticket_number} (Boiler). {{unknown.key}}");
});

test('personal tools stay personal and only a service manager shares one', function (): void {
    $colleague = helpdeskProductivityMember($this->tenant, 'technician');
    $private = CannedResponse::factory()->create(['tenant_id' => $this->tenant->id, 'is_shared' => false, 'owner_user_id' => $colleague->id]);

    $this->getJson('/api/v1/esm/helpdesk/canned-responses')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/canned-responses/{$private->id}/render")->assertNotFound();
    $this->patchJson("/api/v1/esm/helpdesk/canned-responses/{$private->id}", ['title' => 'Mine'])->assertNotFound();

    $this->postJson('/api/v1/esm/helpdesk/canned-responses', ['title' => 'Shared', 'body' => 'Hi', 'is_shared' => true])->assertForbidden();
    $own = $this->postJson('/api/v1/esm/helpdesk/canned-responses', ['title' => 'Mine', 'body' => 'Hi'])->assertCreated()->json('data');
    $this->patchJson("/api/v1/esm/helpdesk/canned-responses/{$own['id']}", ['is_shared' => true])->assertForbidden();

    Sanctum::actingAs(helpdeskProductivityMember($this->tenant, 'service_manager'));
    $this->postJson('/api/v1/esm/helpdesk/macros', ['name' => 'Close', 'is_shared' => true, 'actions' => ['set_status' => 'closed']])->assertCreated();
    $this->postJson('/api/v1/esm/helpdesk/views', ['name' => 'Bad', 'filters' => ['hack' => 'x']])->assertUnprocessable()->assertJsonValidationErrors('filters.hack');
});

test('a macro changes fields, replies and moves the status in one action, or changes nothing', function (): void {
    $team = ServiceTeam::factory()->create(['tenant_id' => $this->tenant->id]);
    $macro = Macro::factory()->create(['tenant_id' => $this->tenant->id, 'actions' => [
        'set_priority' => 'high',
        'assign_team_id' => $team->id,
        'add_tags' => ['Waiting'],
        'set_status' => 'pending_customer',
        'reply' => ['body' => 'Hello {{requester.name}}, please send a photo.'],
    ]]);

    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/macros/{$macro->id}/apply")
        ->assertOk()
        ->assertJsonPath('data.priority', 'high')
        ->assertJsonPath('data.assigned_team_id', $team->id)
        ->assertJsonPath('data.tags', ['waiting'])
        ->assertJsonPath('data.status', 'pending_customer');

    expect(TicketMessage::query()->where('ticket_id', $this->ticket->id)->where('author_type', 'staff')->value('body'))->toBe('Hello Villa Rosa, please send a photo.');

    $closed = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'closed', 'priority' => 'low', 'closed_at' => now()]);
    $holdMacro = Macro::factory()->create(['tenant_id' => $this->tenant->id, 'actions' => ['set_priority' => 'critical', 'set_status' => 'on_hold', 'reply' => ['body' => 'On hold']]]);

    $this->postJson("/api/v1/esm/tickets/{$closed->id}/macros/{$holdMacro->id}/apply")->assertUnprocessable();

    expect($closed->fresh()->priority)->toBe('low')
        ->and(TicketMessage::query()->where('ticket_id', $closed->id)->count())->toBe(0);
});

test('the ticket list filters by status, priority, team, agent, tag, SLA state, customer and text, and applies saved views', function (): void {
    $team = ServiceTeam::factory()->create(['tenant_id' => $this->tenant->id]);
    $technician = ServiceTechnician::factory()->create(['tenant_id' => $this->tenant->id, 'team_id' => $team->id]);
    $breached = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'open', 'priority' => 'critical', 'first_responded_at' => null, 'response_due_at' => now()->subHour(), 'assigned_team_id' => $team->id, 'assigned_technician_id' => $technician->id, 'tags' => ['vip', 'network'], 'subject' => 'Router on fire']);
    $paused = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'pending_customer', 'priority' => 'medium', 'sla_paused_at' => now()]);
    $merged = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'closed', 'priority' => 'medium', 'merged_into_ticket_id' => $breached->id]);

    $ids = fn (string $query): array => collect($this->getJson("/api/v1/esm/tickets?{$query}")->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('status=pending_customer'))->toBe([$paused->id])
        ->and($ids('priority=critical'))->toBe([$breached->id])
        ->and($ids("team_id={$team->id}&agent_id={$technician->id}"))->toBe([$breached->id])
        ->and($ids('tag=vip,network'))->toBe([$breached->id])
        ->and($ids('sla_state=breached'))->toBe([$breached->id])
        ->and($ids('sla_state=paused'))->toBe([$paused->id])
        ->and($ids('sla_state=ok'))->toBe([$this->ticket->id])
        ->and($ids("customer_id={$this->customer->id}"))->toBe([$this->ticket->id])
        ->and($ids('q=router'))->toBe([$breached->id])
        ->and($ids('status=closed'))->toBe([])
        ->and($ids('status=closed&include_merged=1'))->toBe([$merged->id]);

    $view = SavedView::factory()->create(['tenant_id' => $this->tenant->id, 'filters' => ['sla_state' => 'breached']]);
    $private = SavedView::factory()->create(['tenant_id' => $this->tenant->id, 'is_shared' => false, 'owner_user_id' => helpdeskProductivityMember($this->tenant)->id]);

    expect($ids("view_id={$view->id}"))->toBe([$breached->id]);
    $this->getJson("/api/v1/esm/tickets?view_id={$private->id}")->assertNotFound();
    $this->getJson('/api/v1/esm/tickets?sla_state=late')->assertUnprocessable();
});

test('a bulk action changes each ticket on its own and refuses tickets of another company', function (): void {
    $second = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'open']);
    $closed = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'closed', 'closed_at' => now()->subDays(30), 'resolved_at' => now()->subDays(30)]);

    $response = $this->postJson('/api/v1/esm/tickets/bulk', ['ticket_ids' => [$this->ticket->id, $second->id, $closed->id], 'action' => 'status', 'status' => 'on_hold'])
        ->assertOk()->assertJsonPath('meta.succeeded', 2)->assertJsonPath('meta.failed', 1)->json('data');

    expect(collect($response)->firstWhere('ticket_id', $closed->id)['ok'])->toBeFalse()
        ->and($this->ticket->fresh()->status)->toBe('on_hold')
        ->and($closed->fresh()->status)->toBe('closed');

    $this->postJson('/api/v1/esm/tickets/bulk', ['ticket_ids' => [$this->ticket->id, $second->id], 'action' => 'add_tags', 'tags' => ['Q3']])->assertOk()->assertJsonPath('meta.succeeded', 2);
    expect($second->fresh()->tags)->toBe(['q3']);

    $this->postJson('/api/v1/esm/tickets/bulk', ['ticket_ids' => [$this->ticket->id, $this->foreignTicket->id], 'action' => 'priority', 'priority' => 'high'])
        ->assertUnprocessable()->assertJsonValidationErrors('ticket_ids.1');
    expect($this->ticket->fresh()->priority)->toBe('low');

    Sanctum::actingAs(helpdeskProductivityMember($this->tenant, 'accountant'));
    $this->postJson('/api/v1/esm/tickets/bulk', ['ticket_ids' => [$this->ticket->id], 'action' => 'priority', 'priority' => 'high'])->assertForbidden();
});
