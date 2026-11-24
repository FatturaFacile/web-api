<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceManagement\Models\HelpdeskSetting;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketActivity;
use Modules\ServiceManagement\Models\TicketMessage;
use Modules\ServiceManagement\Models\TicketWatcher;

uses(RefreshDatabase::class);

/**
 * A member of the tenant with the given role.
 */
function helpdeskLifecycleMember(Tenant $tenant, string $role = 'admin'): User
{
    $user = User::create(['name' => 'Agent '.Str::random(4), 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->foreignTicket = Ticket::factory()->create(['tenant_id' => $this->other->id]);
    $this->ticket = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'open', 'priority' => 'low', 'customer_id' => null]);
    $this->agent = helpdeskLifecycleMember($this->tenant);
    Sanctum::actingAs($this->agent);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
});

test('an update sets the priority from impact and urgency, normalizes tags and writes the history', function (): void {
    $this->patchJson("/api/v1/esm/tickets/{$this->ticket->id}", [
        'subject' => 'VPN down',
        'impact' => 'high',
        'urgency' => 'high',
        'tags' => ['VPN', ' network ', 'vpn'],
    ])->assertOk()
        ->assertJsonPath('data.subject', 'VPN down')
        ->assertJsonPath('data.priority', 'critical')
        ->assertJsonPath('data.tags', ['network', 'vpn']);

    expect(TicketActivity::query()->where('ticket_id', $this->ticket->id)->pluck('type')->all())->toContain('updated', 'priority_changed');

    $this->patchJson("/api/v1/esm/tickets/{$this->ticket->id}", ['impact' => 'low', 'priority' => 'high'])
        ->assertOk()->assertJsonPath('data.priority', 'high');
});

test('statuses follow the allowed transitions', function (): void {
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/status", ['status' => 'on_hold'])->assertOk()->assertJsonPath('data.status', 'on_hold');
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/status", ['status' => 'resolved'])->assertOk()->assertJsonPath('data.status', 'resolved');
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/status", ['status' => 'pending_customer'])->assertUnprocessable();
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/status", ['status' => 'closed'])->assertOk()->assertJsonPath('data.status', 'closed');
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/status", ['status' => 'on_hold'])->assertUnprocessable();
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/status", ['status' => 'escalated'])->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('pending customer stops the SLA clock and the deadlines move by the time spent waiting', function (): void {
    $this->travelTo('2027-05-03 09:00:00');
    $ticket = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'open', 'response_due_at' => now()->addHours(2), 'resolution_due_at' => now()->addHours(8), 'first_responded_at' => null]);

    $this->postJson("/api/v1/esm/tickets/{$ticket->id}/status", ['status' => 'pending_customer'])
        ->assertOk()->assertJsonPath('data.sla_state', 'paused');

    $this->travelTo('2027-05-03 12:00:00');
    $this->postJson("/api/v1/esm/tickets/{$ticket->id}/status", ['status' => 'open'])->assertOk();

    $ticket->refresh();

    expect($ticket->sla_paused_at)->toBeNull()
        ->and($ticket->sla_paused_minutes)->toBe(180)
        ->and($ticket->response_due_at->toDateTimeString())->toBe('2027-05-03 14:00:00')
        ->and($ticket->resolution_due_at->toDateTimeString())->toBe('2027-05-03 20:00:00');
});

test('a resolved ticket reopens within the window and not after it', function (): void {
    HelpdeskSetting::factory()->create(['tenant_id' => $this->tenant->id, 'reopen_window_days' => 2]);
    $this->travelTo('2027-05-03 09:00:00');
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/status", ['status' => 'resolved'])->assertOk();

    $this->travelTo('2027-05-04 09:00:00');
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/reopen")->assertOk()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.reopened_count', 1);

    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/status", ['status' => 'closed'])->assertOk();
    $this->travelTo('2027-05-07 09:00:00');
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/reopen")->assertUnprocessable();

    expect(TicketActivity::query()->where('ticket_id', $this->ticket->id)->where('type', 'reopened')->count())->toBe(1);
});

test('a merge closes the source, keeps its history in the thread of the target and moves its watchers', function (): void {
    $target = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'open']);
    $sourceMessage = TicketMessage::factory()->create(['ticket_id' => $this->ticket->id, 'body' => 'Original request']);
    $watcher = helpdeskLifecycleMember($this->tenant, 'member');
    TicketWatcher::factory()->create(['ticket_id' => $this->ticket->id, 'user_id' => $watcher->id]);

    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/merge", ['target_ticket_id' => $target->id])
        ->assertOk()->assertJsonPath('data.id', $target->id);

    $source = $this->ticket->fresh();
    expect($source->status)->toBe('closed')
        ->and($source->merged_into_ticket_id)->toBe($target->id)
        ->and(TicketWatcher::query()->where('ticket_id', $target->id)->where('user_id', $watcher->id)->exists())->toBeTrue();

    $thread = $this->getJson("/api/v1/esm/tickets/{$target->id}/messages")->assertOk()->json('data');
    expect(collect($thread)->pluck('id'))->toContain($sourceMessage->id);

    $this->postJson("/api/v1/esm/tickets/{$source->id}/status", ['status' => 'open'])->assertUnprocessable();
    $this->postJson("/api/v1/esm/tickets/{$target->id}/merge", ['target_ticket_id' => $target->id])->assertUnprocessable();
    $this->postJson("/api/v1/esm/tickets/{$target->id}/merge", ['target_ticket_id' => $this->foreignTicket->id])->assertUnprocessable()->assertJsonValidationErrors('target_ticket_id');
});

test('a split moves the chosen messages into a new ticket for the same requester', function (): void {
    $first = TicketMessage::factory()->create(['ticket_id' => $this->ticket->id, 'body' => 'Printer broken']);
    $second = TicketMessage::factory()->create(['ticket_id' => $this->ticket->id, 'body' => 'Also my badge does not work']);

    $child = $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/split", ['subject' => 'Badge', 'message_ids' => [$second->id]])
        ->assertCreated()
        ->assertJsonPath('data.split_from_ticket_id', $this->ticket->id)
        ->json('data');

    expect($second->fresh()->ticket_id)->toBe($child['id'])
        ->and($first->fresh()->ticket_id)->toBe($this->ticket->id);

    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/split", ['subject' => 'X', 'message_ids' => [$second->id]])->assertUnprocessable();
});

test('watchers are colleagues of the tenant', function (): void {
    $colleague = helpdeskLifecycleMember($this->tenant, 'member');
    $stranger = helpdeskLifecycleMember($this->other);

    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/watchers", ['user_id' => $colleague->id])->assertCreated();
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/watchers", ['user_id' => $stranger->id])->assertUnprocessable();
    $this->getJson("/api/v1/esm/tickets/{$this->ticket->id}/watchers")->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/esm/tickets/{$this->ticket->id}/watchers/{$colleague->id}")->assertNoContent();
    $this->getJson("/api/v1/esm/tickets/{$this->ticket->id}/watchers")->assertOk()->assertJsonCount(0, 'data');
});

test('tickets of another company cannot be reached', function (): void {
    $this->patchJson("/api/v1/esm/tickets/{$this->foreignTicket->id}", ['subject' => 'Hijack'])->assertNotFound();
    $this->postJson("/api/v1/esm/tickets/{$this->foreignTicket->id}/status", ['status' => 'closed'])->assertNotFound();
    $this->getJson("/api/v1/esm/tickets/{$this->foreignTicket->id}/activities")->assertNotFound();

    expect($this->foreignTicket->fresh()->subject)->not->toBe('Hijack');
});

test('a role that only reads the service module cannot change tickets', function (): void {
    Sanctum::actingAs(helpdeskLifecycleMember($this->tenant, 'accountant'));

    $this->getJson("/api/v1/esm/tickets/{$this->ticket->id}/activities")->assertOk();
    $this->patchJson("/api/v1/esm/tickets/{$this->ticket->id}", ['subject' => 'No'])->assertForbidden();
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/status", ['status' => 'closed'])->assertForbidden();
});
