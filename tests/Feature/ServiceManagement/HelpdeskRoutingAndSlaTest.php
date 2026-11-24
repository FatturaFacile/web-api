<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\HolidayCalendar;
use Modules\ServiceManagement\Contracts\SLAManagementServiceInterface;
use Modules\ServiceManagement\Jobs\ScanSLABreachesJob;
use Modules\ServiceManagement\Models\BusinessHoursCalendar;
use Modules\ServiceManagement\Models\HelpdeskSetting;
use Modules\ServiceManagement\Models\RoutingRule;
use Modules\ServiceManagement\Models\ServiceCategory;
use Modules\ServiceManagement\Models\ServiceTeam;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketActivity;
use Modules\ServiceManagement\Services\Helpdesk\BusinessHoursClock;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskSlaMonitor;

uses(RefreshDatabase::class);

function helpdeskRoutingMember(Tenant $tenant, string $role = 'admin'): User
{
    $user = User::create(['name' => 'Agent '.Str::random(4), 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

/**
 * Monday to Friday 09:00-18:00 in Rome.
 *
 * @param  array<string, mixed>  $attributes
 */
function helpdeskRomeCalendar(Tenant $tenant, array $attributes = []): BusinessHoursCalendar
{
    return BusinessHoursCalendar::factory()->create(['tenant_id' => $tenant->id, 'timezone' => 'Europe/Rome', ...$attributes]);
}

function helpdeskRunSlaScan(Tenant $tenant): void
{
    (new ScanSLABreachesJob($tenant->id))->handle(app(SLAManagementServiceInterface::class), app(HelpdeskSlaMonitor::class));
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->foreignTeam = ServiceTeam::factory()->create(['tenant_id' => $this->other->id]);
    $this->team = ServiceTeam::factory()->create(['tenant_id' => $this->tenant->id, 'assignment_strategy' => 'round_robin']);
    $this->agents = collect([1, 2])->map(fn (): ServiceTechnician => ServiceTechnician::factory()->create(['tenant_id' => $this->tenant->id, 'team_id' => $this->team->id]))->sortBy('id')->values();
    $this->manager = helpdeskRoutingMember($this->tenant);
    Sanctum::actingAs($this->manager);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
});

test('a keyword rule routes a new ticket to a team, sets priority and tags, and the team picks an agent by round robin', function (): void {
    RoutingRule::factory()->create(['tenant_id' => $this->tenant->id, 'conditions' => ['keywords' => ['vpn']], 'actions' => ['assign_team_id' => $this->team->id, 'set_priority' => 'high', 'add_tags' => ['Network']]]);

    $first = $this->postJson('/api/v1/esm/tickets', ['title' => 'VPN does not connect'])->assertCreated()->json('data');
    $second = $this->postJson('/api/v1/esm/tickets', ['title' => 'My vpn is slow'])->assertCreated()->json('data');
    $third = $this->postJson('/api/v1/esm/tickets', ['title' => 'VPN again'])->assertCreated()->json('data');
    $unrelated = $this->postJson('/api/v1/esm/tickets', ['title' => 'Printer'])->assertCreated()->json('data');

    expect($first['assigned_team_id'])->toBe($this->team->id)
        ->and($first['priority'])->toBe('high')
        ->and($first['tags'])->toBe(['network'])
        ->and([$first['assigned_technician_id'], $second['assigned_technician_id'], $third['assigned_technician_id']])->toBe([$this->agents[0]->id, $this->agents[1]->id, $this->agents[0]->id])
        ->and($unrelated['assigned_team_id'])->toBeNull()
        ->and(TicketActivity::query()->where('ticket_id', $first['id'])->where('type', 'routing_rule_applied')->exists())->toBeTrue();
});

test('rules match on category, source, customer and priority, run by position and can stop the chain', function (): void {
    $category = ServiceCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $customer = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    RoutingRule::factory()->create(['tenant_id' => $this->tenant->id, 'position' => 1, 'conditions' => ['category_ids' => [$category->id], 'sources' => ['email'], 'customer_ids' => [$customer->id], 'priorities' => ['medium']], 'actions' => ['add_tags' => ['vip']], 'stop_processing' => true]);
    RoutingRule::factory()->create(['tenant_id' => $this->tenant->id, 'position' => 2, 'conditions' => [], 'actions' => ['add_tags' => ['catch-all']]]);

    $vip = $this->postJson('/api/v1/esm/tickets', ['title' => 'Help', 'service_category_id' => $category->id, 'channel' => 'email', 'customer_id' => $customer->id])->assertCreated()->json('data');
    $other = $this->postJson('/api/v1/esm/tickets', ['title' => 'Help', 'service_category_id' => $category->id, 'channel' => 'phone', 'customer_id' => $customer->id])->assertCreated()->json('data');

    expect($vip['tags'])->toBe(['vip'])->and($other['tags'])->toBe(['catch-all']);
});

test('least loaded assignment picks the available agent with the fewest active tickets', function (): void {
    $busy = $this->agents[0];
    $idle = $this->agents[1];
    Ticket::factory()->count(2)->create(['tenant_id' => $this->tenant->id, 'status' => 'open', 'assigned_technician_id' => $busy->id]);
    Ticket::factory()->count(3)->create(['tenant_id' => $this->tenant->id, 'status' => 'closed', 'assigned_technician_id' => $idle->id]);
    $ticket = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'new', 'assigned_team_id' => $this->team->id]);

    $this->postJson("/api/v1/esm/tickets/{$ticket->id}/auto-assign", ['strategy' => 'least_loaded'])
        ->assertOk()->assertJsonPath('data.assigned_technician_id', $idle->id)->assertJsonPath('data.status', 'open');

    $idle->update(['is_available' => false]);
    $another = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'assigned_team_id' => $this->team->id]);
    $this->postJson("/api/v1/esm/tickets/{$another->id}/auto-assign", ['strategy' => 'least_loaded'])->assertOk()->assertJsonPath('data.assigned_technician_id', $busy->id);

    $busy->update(['is_available' => false]);
    $none = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'assigned_team_id' => $this->team->id]);
    $this->postJson("/api/v1/esm/tickets/{$none->id}/auto-assign", ['strategy' => 'round_robin'])->assertUnprocessable();
    $this->postJson("/api/v1/esm/tickets/{$none->id}/auto-assign", ['strategy' => 'round_robin', 'team_id' => $this->foreignTeam->id])->assertUnprocessable()->assertJsonValidationErrors('team_id');
});

test('the business hours clock skips nights, weekends and holidays, also those of the HR calendar', function (): void {
    $clock = new BusinessHoursClock;
    $hr = HolidayCalendar::query()->create(['tenant_id' => $this->tenant->id, 'code' => 'CH-TI', 'name' => 'Ticino', 'year' => 2027, 'holidays' => [['date' => '2027-05-04', 'name' => 'Local']]]);
    $calendar = helpdeskRomeCalendar($this->tenant, ['holidays' => ['2027-05-03', '12-25'], 'hr_holiday_calendar_id' => $hr->id]);
    $plain = helpdeskRomeCalendar($this->tenant);

    // Friday 30 April 2027, 17:00 in Rome (15:00 UTC), plus two business hours.
    $friday = CarbonImmutable::parse('2027-04-30 17:00', 'Europe/Rome');

    expect($clock->addBusinessMinutes($friday, 120, $plain)->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2027-05-03 10:00')
        ->and($clock->addBusinessMinutes($friday, 120, $calendar)->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2027-05-05 10:00')
        ->and($clock->addBusinessMinutes(CarbonImmutable::parse('2027-12-24 17:30', 'Europe/Rome'), 60, $calendar)->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2027-12-27 09:30')
        ->and($clock->businessMinutesBetween($friday, CarbonImmutable::parse('2027-05-03 10:00', 'Europe/Rome'), $plain))->toBe(120)
        ->and($clock->addBusinessMinutes($friday, 120, null)->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2027-04-30 19:00');
});

test('the business hours clock counts the real minutes of the days the clocks change', function (): void {
    $clock = new BusinessHoursClock;
    $calendar = helpdeskRomeCalendar($this->tenant, ['weekly_schedule' => ['sun' => [['01:00', '04:00']]]]);

    // 28 March 2027: 02:00 becomes 03:00 in Rome, the window lasts two hours. 31 October 2027: 03:00 becomes 02:00, four hours.
    $springStart = CarbonImmutable::parse('2027-03-28 00:00', 'Europe/Rome');
    $autumnStart = CarbonImmutable::parse('2027-10-31 00:00', 'Europe/Rome');

    expect($clock->businessMinutesBetween($springStart, $springStart->addDay(), $calendar))->toBe(120)
        ->and($clock->businessMinutesBetween($autumnStart, $autumnStart->addDay(), $calendar))->toBe(240)
        ->and($clock->addBusinessMinutes($springStart, 90, $calendar)->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2027-03-28 03:30')
        ->and($clock->addBusinessMinutes($autumnStart, 150, $calendar)->utc()->format('Y-m-d H:i'))->toBe('2027-10-31 01:30');
});

test('SLA deadlines of a new ticket and the pause run on the tenant business hours', function (): void {
    $calendar = helpdeskRomeCalendar($this->tenant);
    HelpdeskSetting::factory()->create(['tenant_id' => $this->tenant->id, 'business_hours_calendar_id' => $calendar->id]);

    $this->travelTo(CarbonImmutable::parse('2027-04-30 17:00', 'Europe/Rome'));
    $ticket = $this->postJson('/api/v1/esm/tickets', ['title' => 'Server down', 'priority' => 'high'])->assertCreated()->json('data');

    expect(CarbonImmutable::parse($ticket['response_due_at'])->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2027-05-03 10:00')
        ->and(CarbonImmutable::parse($ticket['resolution_due_at'])->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2027-05-03 16:00');

    $this->travelTo(CarbonImmutable::parse('2027-04-30 17:30', 'Europe/Rome'));
    $this->postJson("/api/v1/esm/tickets/{$ticket['id']}/status", ['status' => 'pending_customer'])->assertOk();
    $this->travelTo(CarbonImmutable::parse('2027-05-03 09:30', 'Europe/Rome'));
    $this->postJson("/api/v1/esm/tickets/{$ticket['id']}/status", ['status' => 'open'])->assertOk();

    $model = Ticket::query()->findOrFail($ticket['id']);

    expect($model->sla_paused_minutes)->toBe(60)
        ->and($model->response_due_at->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2027-05-03 11:00')
        ->and($model->resolution_due_at->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2027-05-03 17:00');
});

test('the scheduled SLA check warns once, then escalates the breach, and leaves paused tickets alone', function (): void {
    app(TenantContext::class)->setTenant($this->tenant);
    $this->travelTo('2027-05-03 08:00:00');
    $ticket = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'open', 'first_responded_at' => null, 'response_due_at' => now()->addMinutes(100), 'resolution_due_at' => now()->addHours(10), 'escalation_level' => 0]);
    $paused = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'pending_customer', 'sla_paused_at' => now(), 'response_due_at' => now()->addMinutes(10), 'resolution_due_at' => now()->addMinutes(20)]);

    $this->travelTo('2027-05-03 09:25:00');
    helpdeskRunSlaScan($this->tenant);
    helpdeskRunSlaScan($this->tenant);

    expect(TicketActivity::query()->where('ticket_id', $ticket->id)->where('type', 'sla_warning')->count())->toBe(1)
        ->and($ticket->fresh()->response_warning_sent_at)->not->toBeNull()
        ->and($ticket->fresh()->is_escalated)->toBeFalse();

    $this->travelTo('2027-05-03 09:45:00');
    helpdeskRunSlaScan($this->tenant);

    $fresh = $ticket->fresh();
    expect($fresh->response_breached_at)->not->toBeNull()
        ->and($fresh->is_escalated)->toBeTrue()
        ->and($fresh->escalation_level)->toBe(1)
        ->and($fresh->resolution_breached_at)->toBeNull()
        ->and($paused->fresh()->response_breached_at)->toBeNull()
        ->and(TicketActivity::query()->where('ticket_id', $paused->id)->count())->toBe(0);

    $this->assertDatabaseHas('transactional_outbox', ['tenant_id' => $this->tenant->id, 'event_type' => 'esm.ticket.escalated', 'aggregate_id' => $ticket->id]);
});

test('only service managers change routing, business hours and settings, and foreign references are refused', function (): void {
    Sanctum::actingAs(helpdeskRoutingMember($this->tenant, 'technician'));
    $this->getJson('/api/v1/esm/helpdesk/routing-rules')->assertOk();
    $this->postJson('/api/v1/esm/helpdesk/routing-rules', ['name' => 'X', 'actions' => ['set_priority' => 'high']])->assertForbidden();
    $this->putJson('/api/v1/esm/helpdesk/settings', ['reopen_window_days' => 1])->assertForbidden();
    $this->putJson("/api/v1/esm/helpdesk/teams/{$this->team->id}/assignment", ['assignment_strategy' => 'manual'])->assertForbidden();

    Sanctum::actingAs($this->manager);
    $this->postJson('/api/v1/esm/helpdesk/routing-rules', ['name' => 'X', 'actions' => ['assign_team_id' => $this->foreignTeam->id]])->assertUnprocessable()->assertJsonValidationErrors('actions.assign_team_id');
    $this->postJson('/api/v1/esm/helpdesk/routing-rules', ['name' => 'Network', 'conditions' => ['keywords' => ['vpn']], 'actions' => ['assign_team_id' => $this->team->id]])->assertCreated();

    $calendar = $this->postJson('/api/v1/esm/helpdesk/business-hours', ['code' => 'STD', 'name' => 'Office', 'timezone' => 'Europe/Zurich', 'weekly_schedule' => ['mon' => [['08:00', '12:00'], ['13:00', '17:00']]], 'holidays' => ['2027-08-01']])
        ->assertCreated()->json('data');
    $this->postJson('/api/v1/esm/helpdesk/business-hours', ['code' => 'BAD', 'name' => 'Bad', 'timezone' => 'Europe/Zurich', 'weekly_schedule' => ['mon' => [['17:00', '08:00']]]])->assertUnprocessable();

    $this->putJson('/api/v1/esm/helpdesk/settings', ['reopen_window_days' => 3, 'business_hours_calendar_id' => $calendar['id']])->assertOk()->assertJsonPath('data.reopen_window_days', 3);
    $this->getJson('/api/v1/esm/helpdesk/settings')->assertOk()->assertJsonPath('data.business_hours_calendar_id', $calendar['id']);
    $this->putJson("/api/v1/esm/helpdesk/teams/{$this->foreignTeam->id}/assignment", ['assignment_strategy' => 'manual'])->assertNotFound();
});
