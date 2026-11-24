<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Portal\PortalTokenService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceManagement\Mail\HelpdeskTicketMail;
use Modules\ServiceManagement\Models\HelpdeskNotification;
use Modules\ServiceManagement\Models\ServiceTeam;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketWatcher;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskSlaMonitor;

uses(RefreshDatabase::class);

function helpdeskNotificationMember(Tenant $tenant, string $role = 'technician'): User
{
    $user = User::create(['name' => 'User '.Str::random(4), 'email' => Str::lower(Str::random(8)).'@staff.test', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function helpdeskMailedTo(string $address): int
{
    return Mail::sent(HelpdeskTicketMail::class, fn (HelpdeskTicketMail $mail): bool => $mail->hasTo($address))->count();
}

beforeEach(function (): void {
    Mail::fake();
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->lead = helpdeskNotificationMember($this->tenant, 'service_manager');
    $this->team = ServiceTeam::factory()->create(['tenant_id' => $this->tenant->id, 'lead_user_id' => $this->lead->id]);
    $this->agentUser = helpdeskNotificationMember($this->tenant);
    $this->technician = ServiceTechnician::factory()->create(['tenant_id' => $this->tenant->id, 'team_id' => $this->team->id, 'user_id' => $this->agentUser->id]);
    $this->watcher = helpdeskNotificationMember($this->tenant);
    $this->customer = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'client@customer.test']);
    $this->ticket = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'open', 'customer_id' => $this->customer->id, 'assigned_team_id' => $this->team->id, 'assigned_technician_id' => $this->technician->id, 'metadata' => []]);
    TicketWatcher::factory()->create(['ticket_id' => $this->ticket->id, 'user_id' => $this->watcher->id]);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
});

test('a customer reply notifies the assignee and the watchers, in the inbox and by e-mail', function (): void {
    $token = app(PortalTokenService::class)->createToken($this->customer)['token'];
    $this->postJson("/api/v1/portal/helpdesk/{$token}/tickets/{$this->ticket->id}/reply", ['message' => 'Still broken'])->assertCreated();

    expect(HelpdeskNotification::query()->where('event', 'ticket_replied')->pluck('user_id')->sort()->values()->all())->toBe(collect([$this->agentUser->id, $this->watcher->id])->sort()->values()->all())
        ->and(helpdeskMailedTo($this->agentUser->email))->toBe(1)
        ->and(helpdeskMailedTo($this->watcher->email))->toBe(1)
        ->and(helpdeskMailedTo($this->lead->email))->toBe(0);
});

test('the author of a change is not notified and each user chooses the channels per event', function (): void {
    Sanctum::actingAs($this->watcher);
    $this->putJson('/api/v1/esm/helpdesk/notification-preferences', ['preferences' => [['event' => 'ticket_replied', 'mail' => false, 'in_app' => true]]])
        ->assertOk()->assertJsonFragment(['event' => 'ticket_replied', 'mail' => false, 'in_app' => true])->assertJsonFragment(['event' => 'status_changed', 'mail' => true, 'in_app' => true]);

    Sanctum::actingAs($this->agentUser);
    $this->putJson('/api/v1/esm/helpdesk/notification-preferences', ['preferences' => [['event' => 'status_changed', 'mail' => false, 'in_app' => false]]])->assertOk();
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/messages", ['body' => 'Internal', 'visibility' => 'internal'])->assertCreated();

    expect(HelpdeskNotification::query()->where('user_id', $this->agentUser->id)->count())->toBe(0)
        ->and(HelpdeskNotification::query()->where('user_id', $this->watcher->id)->where('event', 'ticket_replied')->count())->toBe(1)
        ->and(helpdeskMailedTo($this->watcher->email))->toBe(0);

    Sanctum::actingAs($this->watcher);
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/status", ['status' => 'on_hold'])->assertOk();

    expect(HelpdeskNotification::query()->where('user_id', $this->agentUser->id)->where('event', 'status_changed')->count())->toBe(0)
        ->and(helpdeskMailedTo($this->agentUser->email))->toBe(0);

    $this->putJson('/api/v1/esm/helpdesk/notification-preferences', ['preferences' => [['event' => 'unknown', 'mail' => true, 'in_app' => true]]])->assertUnprocessable();
});

test('the requester hears of a new ticket and of status changes, the team lead of an unassigned ticket, an agent of an assignment', function (): void {
    Sanctum::actingAs($this->lead);
    $created = $this->postJson('/api/v1/esm/tickets', ['title' => 'Printer', 'contact_email' => 'new@customer.test', 'service_team_id' => $this->team->id])->assertCreated()->json('data');

    expect(HelpdeskNotification::query()->where('ticket_id', $created['id'])->where('event', 'ticket_created')->count())->toBe(0)
        ->and(Mail::sent(HelpdeskTicketMail::class, fn (HelpdeskTicketMail $mail): bool => $mail->hasTo('new@customer.test') && $mail->isAutomatic && str_contains($mail->body, 'opened ticket'))->count())->toBe(1);

    Sanctum::actingAs($this->watcher);
    $other = $this->postJson('/api/v1/esm/tickets', ['title' => 'Badge', 'service_team_id' => $this->team->id])->assertCreated()->json('data');
    expect(HelpdeskNotification::query()->where('ticket_id', $other['id'])->where('user_id', $this->lead->id)->where('event', 'ticket_created')->count())->toBe(1);

    $this->postJson("/api/v1/esm/tickets/{$created['id']}/status", ['status' => 'pending_customer'])->assertOk();
    expect(Mail::sent(HelpdeskTicketMail::class, fn (HelpdeskTicketMail $mail): bool => $mail->hasTo('new@customer.test') && str_contains($mail->body, 'need some information'))->count())->toBe(1);

    $this->patchJson("/api/v1/esm/tickets/{$other['id']}", ['assigned_technician_id' => $this->technician->id])->assertOk();
    expect(HelpdeskNotification::query()->where('ticket_id', $other['id'])->where('user_id', $this->agentUser->id)->where('event', 'ticket_assigned')->count())->toBe(1);
});

test('SLA warnings reach the assignee and the watchers, breaches also the team lead', function (): void {
    app(TenantContext::class)->setTenant($this->tenant);
    $this->travelTo('2027-05-03 08:00:00');
    $ticket = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'open', 'assigned_team_id' => $this->team->id, 'assigned_technician_id' => $this->technician->id, 'first_responded_at' => null, 'response_due_at' => now()->addMinutes(100), 'resolution_due_at' => now()->addDay()]);

    $this->travelTo('2027-05-03 09:30:00');
    app(HelpdeskSlaMonitor::class)->scan($this->tenant->id);
    expect(HelpdeskNotification::query()->where('ticket_id', $ticket->id)->where('event', 'sla_warning')->pluck('user_id')->all())->toBe([$this->agentUser->id]);

    $this->travelTo('2027-05-03 10:00:00');
    app(HelpdeskSlaMonitor::class)->scan($this->tenant->id);
    expect(HelpdeskNotification::query()->where('ticket_id', $ticket->id)->where('event', 'sla_breached')->pluck('user_id')->sort()->values()->all())->toBe(collect([$this->agentUser->id, $this->lead->id])->sort()->values()->all());
});

test('everybody reads only their own notifications', function (): void {
    $mine = HelpdeskNotification::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->watcher->id, 'ticket_id' => $this->ticket->id, 'event' => 'ticket_replied', 'title' => 'Mine']);
    $theirs = HelpdeskNotification::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->agentUser->id, 'ticket_id' => $this->ticket->id, 'event' => 'ticket_replied', 'title' => 'Theirs']);

    Sanctum::actingAs($this->watcher);
    $this->getJson('/api/v1/esm/helpdesk/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.unread', 1)->assertJsonPath('data.0.title', 'Mine');
    $this->postJson("/api/v1/esm/helpdesk/notifications/{$mine->id}/read")->assertOk();
    $this->getJson('/api/v1/esm/helpdesk/notifications?unread=1')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/esm/helpdesk/notifications/{$theirs->id}/read")->assertNotFound();
});
