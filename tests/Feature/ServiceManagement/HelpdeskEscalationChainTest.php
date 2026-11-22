<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceManagement\Models\EscalationChain;
use Modules\ServiceManagement\Models\HelpdeskNotification;
use Modules\ServiceManagement\Models\ServiceTeam;
use Modules\ServiceManagement\Models\SLAPolicy;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketActivity;
use Modules\ServiceManagement\Models\TicketEscalationLog;
use Modules\ServiceManagement\Services\Helpdesk\EscalationChainEvaluator;

uses(RefreshDatabase::class);

function escalationMember(Tenant $tenant, string $role = 'admin'): User
{
    $user = User::create(['name' => 'Member '.Str::random(4), 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

/**
 * Scans the tenant at the given instant.
 */
function escalationScan(Tenant $tenant, string $at): int
{
    return app(EscalationChainEvaluator::class)->scan($tenant->id, CarbonImmutable::parse($at, 'UTC'))['fired'];
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->manager = escalationMember($this->tenant);
    $this->recipient = escalationMember($this->tenant, 'member');
    $this->lead = escalationMember($this->tenant, 'member');
    $this->teamA = ServiceTeam::factory()->create(['tenant_id' => $this->tenant->id, 'lead_user_id' => $this->lead->id]);
    $this->teamB = ServiceTeam::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->foreignTeam = ServiceTeam::factory()->create(['tenant_id' => $this->other->id]);
    Sanctum::actingAs($this->manager);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);

    $this->chainPayload = [
        'code' => 'STANDARD',
        'name' => 'Standard escalation',
        'steps' => [
            ['target' => 'response', 'threshold_percent' => 75, 'action' => 'notify', 'notify_user_ids' => [$this->recipient->id]],
            ['target' => 'response', 'threshold_percent' => 100, 'action' => 'raise_priority', 'priority' => 'critical'],
            ['target' => 'response', 'threshold_percent' => 125, 'action' => 'reassign_team', 'team_id' => $this->teamB->id],
            ['target' => 'resolution', 'threshold_percent' => 90, 'action' => 'notify', 'notify_team_lead' => true],
        ],
    ];
});

/**
 * A chain wired to a new policy and an open ticket created at 10:00 UTC on 1 June 2027 with a 4 hour response target and a
 * 24 hour resolution target.
 *
 * @return array{policy: SLAPolicy, chain: EscalationChain, ticket: Ticket}
 */
function escalationFixture(object $test): array
{
    $chainId = $test->postJson('/api/v1/esm/helpdesk/escalation-chains', $test->chainPayload)->assertCreated()->json('data.id');
    $policy = SLAPolicy::factory()->create(['tenant_id' => $test->tenant->id, 'business_hours_only' => false]);
    $test->putJson("/api/v1/esm/helpdesk/sla-policies/{$policy->id}/settings", ['escalation_chain_id' => $chainId])->assertOk();

    $ticket = Ticket::factory()->create([
        'tenant_id' => $test->tenant->id,
        'status' => 'open',
        'priority' => 'medium',
        'assigned_team_id' => $test->teamA->id,
        'first_responded_at' => null,
        'created_at' => '2027-06-01 10:00:00',
        'response_due_at' => '2027-06-01 14:00:00',
        'resolution_due_at' => '2027-06-02 10:00:00',
        'metadata' => ['sla_policy_id' => $policy->id],
    ]);

    return ['policy' => $policy, 'chain' => EscalationChain::query()->findOrFail($chainId), 'ticket' => $ticket];
}

test('a chain is configured with steps, listed, changed and deleted per tenant', function (): void {
    $created = $this->postJson('/api/v1/esm/helpdesk/escalation-chains', $this->chainPayload)->assertCreated()
        ->assertJsonCount(4, 'data.steps')->assertJsonPath('data.steps.0.threshold_percent', 75)->json('data');

    $this->getJson('/api/v1/esm/helpdesk/escalation-chains')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/esm/helpdesk/escalation-chains', $this->chainPayload)->assertUnprocessable()->assertJsonValidationErrors('code');

    $this->patchJson("/api/v1/esm/helpdesk/escalation-chains/{$created['id']}", ['name' => 'Renamed', 'steps' => [['target' => 'resolution', 'threshold_percent' => 100, 'action' => 'raise_priority', 'priority' => 'high']]])
        ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonCount(1, 'data.steps');

    $policy = SLAPolicy::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->putJson("/api/v1/esm/helpdesk/sla-policies/{$policy->id}/settings", ['escalation_chain_id' => $created['id'], 'next_update_interval_minutes' => 60, 'pause_on_on_hold' => true])
        ->assertOk()->assertJsonPath('data.next_update_interval_minutes', 60)->assertJsonPath('data.pause_on_on_hold', true);

    $this->deleteJson("/api/v1/esm/helpdesk/escalation-chains/{$created['id']}")->assertNoContent();
    expect($policy->fresh()->escalation_chain_id)->toBeNull()
        ->and(EscalationChain::query()->count())->toBe(0);
});

test('only service managers configure escalation, every member can read it', function (): void {
    $chain = $this->postJson('/api/v1/esm/helpdesk/escalation-chains', $this->chainPayload)->assertCreated()->json('data');
    $policy = SLAPolicy::factory()->create(['tenant_id' => $this->tenant->id]);
    Sanctum::actingAs($this->recipient);

    $this->getJson('/api/v1/esm/helpdesk/escalation-chains')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/esm/helpdesk/escalation-chains', [...$this->chainPayload, 'code' => 'OTHER'])->assertForbidden();
    $this->patchJson("/api/v1/esm/helpdesk/escalation-chains/{$chain['id']}", ['name' => 'Hijack'])->assertForbidden();
    $this->deleteJson("/api/v1/esm/helpdesk/escalation-chains/{$chain['id']}")->assertForbidden();
    $this->putJson("/api/v1/esm/helpdesk/sla-policies/{$policy->id}/settings", ['escalation_chain_id' => $chain['id']])->assertForbidden();

    expect(EscalationChain::query()->count())->toBe(1);
});

test('a step needs what its action needs and the references stay inside the tenant', function (): void {
    $step = ['target' => 'response', 'threshold_percent' => 75];
    $invalid = fn (array $steps) => $this->postJson('/api/v1/esm/helpdesk/escalation-chains', ['code' => 'X', 'name' => 'X', 'steps' => $steps])->assertUnprocessable();

    $invalid([[...$step, 'action' => 'notify']])->assertJsonValidationErrors('steps.0.notify_user_ids');
    $invalid([[...$step, 'action' => 'reassign_team']])->assertJsonValidationErrors('steps.0.team_id');
    $invalid([[...$step, 'action' => 'raise_priority']])->assertJsonValidationErrors('steps.0.priority');
    $invalid([[...$step, 'action' => 'reassign_team', 'team_id' => $this->foreignTeam->id]])->assertJsonValidationErrors('steps.0.team_id');
    $invalid([[...$step, 'action' => 'notify', 'notify_user_ids' => [escalationMember($this->other)->id]]])->assertJsonValidationErrors('steps.0.notify_user_ids.0');
    $invalid([[...$step, 'target' => 'pizza', 'action' => 'notify', 'notify_team_lead' => true]])->assertJsonValidationErrors('steps.0.target');

    $policy = SLAPolicy::factory()->create(['tenant_id' => $this->tenant->id]);
    $foreignChain = EscalationChain::query()->create(['tenant_id' => $this->other->id, 'code' => 'F', 'name' => 'Foreign']);
    $this->putJson("/api/v1/esm/helpdesk/sla-policies/{$policy->id}/settings", ['escalation_chain_id' => $foreignChain->id])->assertUnprocessable()->assertJsonValidationErrors('escalation_chain_id');
});

test('steps fire once when their share of the target has passed, in order, and never again', function (): void {
    ['ticket' => $ticket] = escalationFixture($this);

    expect(escalationScan($this->tenant, '2027-06-01 12:59:00'))->toBe(0)
        ->and(TicketEscalationLog::query()->count())->toBe(0);

    // 75% of the 4 hour response target: the manager is notified.
    expect(escalationScan($this->tenant, '2027-06-01 13:00:00'))->toBe(1);
    $notification = HelpdeskNotification::query()->where('user_id', $this->recipient->id)->sole();
    expect($notification->ticket_id)->toBe($ticket->id)
        ->and($notification->event)->toBe('sla_escalation')
        ->and($notification->title)->toContain($ticket->ticket_number)->toContain('75%')
        ->and(TicketActivity::query()->where('ticket_id', $ticket->id)->where('type', 'sla_escalation')->count())->toBe(1);

    // A repeated scan does not notify twice.
    expect(escalationScan($this->tenant, '2027-06-01 13:00:00'))->toBe(0)
        ->and(escalationScan($this->tenant, '2027-06-01 13:30:00'))->toBe(0)
        ->and(HelpdeskNotification::query()->where('user_id', $this->recipient->id)->count())->toBe(1);

    // Breach: the priority goes up.
    expect(escalationScan($this->tenant, '2027-06-01 14:01:00'))->toBe(1)
        ->and($ticket->fresh()->priority)->toBe('critical')
        ->and($ticket->fresh()->is_escalated)->toBeTrue();

    // 125%: the ticket moves to the other queue.
    expect(escalationScan($this->tenant, '2027-06-01 15:00:00'))->toBe(1)
        ->and($ticket->fresh()->assigned_team_id)->toBe($this->teamB->id);

    // 90% of the resolution target: the lead of the queue the ticket sits in is notified.
    $this->teamB->update(['lead_user_id' => $this->lead->id]);
    expect(escalationScan($this->tenant, '2027-06-02 07:40:00'))->toBe(1)
        ->and(HelpdeskNotification::query()->where('user_id', $this->lead->id)->count())->toBe(1)
        ->and(TicketEscalationLog::query()->where('ticket_id', $ticket->id)->orderBy('fired_at')->pluck('threshold_percent')->all())->toBe([75, 100, 125, 90]);

    // Everything fired: nothing is left to do.
    expect(escalationScan($this->tenant, '2027-06-03 12:00:00'))->toBe(0);
});

test('a scan that finds several crossed thresholds fires them all in one pass', function (): void {
    ['ticket' => $ticket] = escalationFixture($this);

    expect(escalationScan($this->tenant, '2027-06-01 15:30:00'))->toBe(3)
        ->and($ticket->fresh()->priority)->toBe('critical')
        ->and($ticket->fresh()->assigned_team_id)->toBe($this->teamB->id);

    $log = $this->getJson("/api/v1/esm/tickets/{$ticket->id}/escalations")->assertOk()->assertJsonCount(3, 'data');
    expect(collect($log->json('data'))->pluck('action')->sort()->values()->all())->toBe(['notify', 'raise_priority', 'reassign_team'])
        ->and($log->json('data.0.elapsed_percent'))->toBeGreaterThanOrEqual(75);
});

test('the clock stops the escalation: waiting, paused, answered, resolved and merged tickets are left alone', function (): void {
    ['ticket' => $ticket, 'policy' => $policy] = escalationFixture($this);
    $copy = fn (array $overrides): Ticket => Ticket::factory()->create([
        'tenant_id' => $this->tenant->id, 'status' => 'open', 'created_at' => '2027-06-01 10:00:00', 'response_due_at' => '2027-06-01 14:00:00', 'resolution_due_at' => '2027-06-02 10:00:00',
        'first_responded_at' => null, 'metadata' => ['sla_policy_id' => $policy->id], ...$overrides,
    ]);
    $waiting = $copy(['status' => 'pending_customer']);
    $paused = $copy(['sla_paused_at' => '2027-06-01 11:00:00']);
    $resolved = $copy(['status' => 'resolved', 'resolved_at' => '2027-06-01 11:00:00']);
    $merged = $copy(['merged_into_ticket_id' => $ticket->id]);
    $noPolicy = $copy(['metadata' => ['is_sla_breached' => false]]);
    $answered = $copy(['first_responded_at' => '2027-06-01 10:30:00', 'resolution_due_at' => '2027-06-01 15:00:00']);

    escalationScan($this->tenant, '2027-06-01 15:30:00');

    $touched = TicketEscalationLog::query()->pluck('ticket_id')->unique()->all();
    expect($touched)->toContain($ticket->id, $answered->id)
        ->and($touched)->not->toContain($waiting->id)->not->toContain($paused->id)->not->toContain($resolved->id)->not->toContain($merged->id)->not->toContain($noPolicy->id);

    // An answered ticket no longer runs the first response steps, only the resolution ones (its resolution target is past).
    expect(TicketEscalationLog::query()->where('ticket_id', $answered->id)->where('target', 'response')->count())->toBe(0);
});

test('the escalations of one tenant never touch the tickets of another', function (): void {
    escalationFixture($this);
    $foreign = Ticket::factory()->create(['tenant_id' => $this->other->id, 'status' => 'open', 'created_at' => '2027-06-01 10:00:00', 'response_due_at' => '2027-06-01 14:00:00', 'first_responded_at' => null]);

    expect(escalationScan($this->other, '2027-06-01 15:30:00'))->toBe(0)
        ->and(escalationScan($this->tenant, '2027-06-01 15:30:00'))->toBe(3)
        ->and(TicketEscalationLog::query()->where('ticket_id', $foreign->id)->count())->toBe(0);
});

test('the next update target restarts after each agent reply and escalates once per cycle', function (): void {
    $chain = $this->postJson('/api/v1/esm/helpdesk/escalation-chains', [
        'code' => 'UPDATES', 'name' => 'Updates',
        'steps' => [['target' => 'next_update', 'threshold_percent' => 100, 'action' => 'notify', 'notify_user_ids' => [$this->recipient->id]]],
    ])->assertCreated()->json('data.id');
    $policy = SLAPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'business_hours_only' => false]);
    $this->putJson("/api/v1/esm/helpdesk/sla-policies/{$policy->id}/settings", ['escalation_chain_id' => $chain, 'next_update_interval_minutes' => 60])->assertOk();
    $ticket = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'open', 'first_responded_at' => null, 'response_due_at' => null, 'resolution_due_at' => null, 'metadata' => ['sla_policy_id' => $policy->id]]);

    $this->travelTo(CarbonImmutable::parse('2027-06-01 10:00:00', 'UTC'));
    $this->postJson("/api/v1/esm/tickets/{$ticket->id}/messages", ['body' => 'Looking into it', 'visibility' => 'internal'])->assertCreated();
    expect($ticket->fresh()->next_update_due_at)->toBeNull();

    $this->postJson("/api/v1/esm/tickets/{$ticket->id}/messages", ['body' => 'We are on it', 'visibility' => 'public'])->assertCreated();
    expect($ticket->fresh()->next_update_due_at->toIso8601String())->toBe('2027-06-01T11:00:00+00:00');

    expect(escalationScan($this->tenant, '2027-06-01 10:59:00'))->toBe(0);
    expect(escalationScan($this->tenant, '2027-06-01 11:01:00'))->toBe(1)
        ->and($ticket->fresh()->next_update_breached_at)->not->toBeNull()
        ->and(escalationScan($this->tenant, '2027-06-01 11:30:00'))->toBe(0);

    // Another public reply opens the next cycle: the step may fire again, once.
    $this->travelTo(CarbonImmutable::parse('2027-06-01 12:00:00', 'UTC'));
    $this->postJson("/api/v1/esm/tickets/{$ticket->id}/messages", ['body' => 'Still working', 'visibility' => 'public'])->assertCreated();
    expect($ticket->fresh()->next_update_due_at->toIso8601String())->toBe('2027-06-01T13:00:00+00:00')
        ->and($ticket->fresh()->next_update_breached_at)->toBeNull()
        ->and(escalationScan($this->tenant, '2027-06-01 13:05:00'))->toBe(1)
        ->and(escalationScan($this->tenant, '2027-06-01 13:06:00'))->toBe(0)
        ->and(TicketEscalationLog::query()->where('ticket_id', $ticket->id)->count())->toBe(2)
        ->and(HelpdeskNotification::query()->where('user_id', $this->recipient->id)->count())->toBe(2);
});

test('a policy without a next-update interval leaves the target off', function (): void {
    ['ticket' => $ticket] = escalationFixture($this);

    $this->postJson("/api/v1/esm/tickets/{$ticket->id}/messages", ['body' => 'Hello', 'visibility' => 'public'])->assertCreated();

    expect($ticket->fresh()->next_update_due_at)->toBeNull();
});

test('on hold stops the clock only for policies that ask for it, and resuming moves the deadlines', function (): void {
    $policy = SLAPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'business_hours_only' => false, 'pause_on_on_hold' => true, 'next_update_interval_minutes' => 60]);
    $plain = SLAPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'business_hours_only' => false]);
    $make = fn (SLAPolicy $forPolicy): Ticket => Ticket::factory()->create([
        'tenant_id' => $this->tenant->id, 'status' => 'open', 'first_responded_at' => null,
        'response_due_at' => '2027-06-01 14:00:00', 'resolution_due_at' => '2027-06-02 10:00:00', 'next_update_due_at' => '2027-06-01 12:00:00',
        'metadata' => ['sla_policy_id' => $forPolicy->id],
    ]);
    $pausing = $make($policy);
    $running = $make($plain);

    $this->travelTo(CarbonImmutable::parse('2027-06-01 11:00:00', 'UTC'));
    $this->postJson("/api/v1/esm/tickets/{$pausing->id}/status", ['status' => 'on_hold'])->assertOk();
    $this->postJson("/api/v1/esm/tickets/{$running->id}/status", ['status' => 'on_hold'])->assertOk();

    expect($pausing->fresh()->sla_paused_at)->not->toBeNull()
        ->and($running->fresh()->sla_paused_at)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2027-06-01 12:00:00', 'UTC'));
    $this->postJson("/api/v1/esm/tickets/{$pausing->id}/status", ['status' => 'open'])->assertOk();

    $resumed = $pausing->fresh();
    expect($resumed->sla_paused_at)->toBeNull()
        ->and($resumed->sla_paused_minutes)->toBe(60)
        ->and($resumed->response_due_at->toIso8601String())->toBe('2027-06-01T15:00:00+00:00')
        ->and($resumed->resolution_due_at->toIso8601String())->toBe('2027-06-02T11:00:00+00:00')
        ->and($resumed->next_update_due_at->toIso8601String())->toBe('2027-06-01T13:00:00+00:00');
});
