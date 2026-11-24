<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\ServiceTeam;
use Modules\ServiceManagement\Models\SLAPolicy;
use Modules\ServiceManagement\Models\Ticket;

uses(RefreshDatabase::class);

function insightsMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role), 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->teamA = ServiceTeam::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->teamB = ServiceTeam::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin = insightsMember($this->tenant, 'admin');
    Sanctum::actingAs($this->admin);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
});

/**
 * An open ticket with only the response target set, created and due at the given times.
 *
 * @param  array<string, mixed>  $overrides
 */
function riskTicket(object $test, string $createdAt, string $dueAt, array $overrides = []): Ticket
{
    return Ticket::factory()->create([
        'tenant_id' => $test->tenant->id, 'status' => 'open', 'first_responded_at' => null, 'resolution_due_at' => null, 'next_update_due_at' => null,
        'created_at' => $createdAt, 'response_due_at' => $dueAt, ...$overrides,
    ]);
}

test('the breach risk queue lists the tickets near or past a target, the most urgent first', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-06-01 13:30:00', 'UTC'));
    $atRisk = riskTicket($this, '2027-06-01 10:00:00', '2027-06-01 14:00:00');
    $breached = riskTicket($this, '2027-06-01 08:00:00', '2027-06-01 12:00:00');
    riskTicket($this, '2027-06-01 12:30:00', '2027-06-01 14:30:00');
    riskTicket($this, '2027-06-01 10:00:00', '2027-06-01 14:00:00', ['status' => 'pending_customer']);
    riskTicket($this, '2027-06-01 10:00:00', '2027-06-01 14:00:00', ['sla_paused_at' => '2027-06-01 11:00:00']);
    riskTicket($this, '2027-06-01 10:00:00', '2027-06-01 14:00:00', ['status' => 'resolved']);
    riskTicket($this, '2027-06-01 10:00:00', '2027-06-01 14:00:00', ['first_responded_at' => '2027-06-01 11:00:00']);
    riskTicket($this, '2027-06-01 08:00:00', '2027-06-01 12:00:00', ['merged_into_ticket_id' => $breached->id]);
    Ticket::factory()->create(['tenant_id' => $this->other->id, 'status' => 'open', 'first_responded_at' => null, 'created_at' => '2027-06-01 08:00:00', 'response_due_at' => '2027-06-01 12:00:00']);

    $queue = $this->getJson('/api/v1/esm/helpdesk/breach-risk?risk_percent=75')->assertOk()->assertJsonCount(2, 'data')->json('data');

    expect(array_column($queue, 'ticket_id'))->toBe([$breached->id, $atRisk->id])
        ->and($queue[0]['risk_percent'])->toBe(137)
        ->and($queue[0]['targets'][0])->toMatchArray(['target' => 'response', 'state' => 'breached', 'elapsed_percent' => 137])
        ->and($queue[1]['risk_percent'])->toBe(87)
        ->and($queue[1]['targets'][0])->toMatchArray(['target' => 'response', 'state' => 'at_risk']);

    // A lower threshold brings the 50% ticket in, a higher one drops the 87% one.
    $this->getJson('/api/v1/esm/helpdesk/breach-risk?risk_percent=50')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/v1/esm/helpdesk/breach-risk?risk_percent=90')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/esm/helpdesk/breach-risk?risk_percent=50&limit=1')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/esm/helpdesk/breach-risk?risk_percent=0')->assertUnprocessable()->assertJsonValidationErrors('risk_percent');
});

test('the breach risk queue watches the resolution and next update targets too', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-06-01 13:30:00', 'UTC'));
    $policy = SLAPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'next_update_interval_minutes' => 60]);
    $ticket = Ticket::factory()->create([
        'tenant_id' => $this->tenant->id, 'status' => 'open', 'first_responded_at' => '2027-06-01 12:00:00', 'response_due_at' => '2027-06-01 11:00:00',
        'created_at' => '2027-06-01 08:00:00', 'resolution_due_at' => '2027-06-01 14:00:00', 'next_update_due_at' => '2027-06-01 13:40:00',
        'metadata' => ['sla_policy_id' => $policy->id],
    ]);

    $row = $this->getJson('/api/v1/esm/helpdesk/breach-risk')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
    $targets = collect($row['targets'])->keyBy('target');

    expect($row['ticket_id'])->toBe($ticket->id)
        ->and($targets->keys()->sort()->values()->all())->toBe(['next_update', 'resolution'])
        // 8:00 to 14:00 is 6 hours, 5.5 are gone; the next update started one policy interval (60 minutes) before it is due, 50 of them are gone.
        ->and($targets['resolution']['elapsed_percent'])->toBe(91)
        ->and($targets['next_update']['elapsed_percent'])->toBe(83);
});

test('CSAT, mean times and SLA compliance are summarised by queue, priority and contract', function (): void {
    $contract = ServiceContract::factory()->create(['tenant_id' => $this->tenant->id]);
    $ticket = fn (array $attributes): Ticket => Ticket::factory()->create([
        'tenant_id' => $this->tenant->id, 'created_at' => '2027-06-01 10:00:00', 'first_responded_at' => null, 'resolved_at' => null, 'csat_score' => null,
        'response_due_at' => '2027-06-01 11:00:00', 'resolution_due_at' => '2027-06-01 13:00:00', ...$attributes,
    ]);

    // Queue A: one ticket answered in 30 minutes and resolved in 2 hours inside its targets, one answered in 90 minutes and resolved in 4 hours outside them.
    $ticket(['assigned_team_id' => $this->teamA->id, 'priority' => 'high', 'service_contract_id' => $contract->id, 'first_responded_at' => '2027-06-01 10:30:00', 'resolved_at' => '2027-06-01 12:00:00', 'csat_score' => 5]);
    $ticket(['assigned_team_id' => $this->teamA->id, 'priority' => 'low', 'first_responded_at' => '2027-06-01 11:30:00', 'resolved_at' => '2027-06-01 14:00:00', 'csat_score' => 3]);
    // Queue B: still unanswered.
    $ticket(['assigned_team_id' => $this->teamB->id, 'priority' => 'high']);
    // Outside the period and of another tenant: ignored.
    $ticket(['assigned_team_id' => $this->teamA->id, 'created_at' => '2027-05-01 10:00:00', 'csat_score' => 1]);
    Ticket::factory()->create(['tenant_id' => $this->other->id, 'created_at' => '2027-06-01 10:00:00', 'csat_score' => 1]);

    $report = $this->getJson('/api/v1/esm/helpdesk/metrics?from=2027-06-01&to=2027-06-01')->assertOk()->assertJsonPath('data.group_by', 'queue')->json('data');
    $groups = collect($report['groups'])->keyBy('key');

    expect($report['totals']['tickets'])->toBe(3)
        ->and($report['totals']['mttfr_minutes'])->toEqual(60.0)
        ->and($report['totals']['mttr_minutes'])->toEqual(180.0)
        ->and($groups[$this->teamA->id]['tickets'])->toBe(2)
        ->and($groups[$this->teamA->id]['mttfr_minutes'])->toEqual(60.0)
        ->and($groups[$this->teamA->id]['mttr_minutes'])->toEqual(180.0)
        ->and($groups[$this->teamA->id]['first_response_compliance_percent'])->toEqual(50.0)
        ->and($groups[$this->teamA->id]['resolution_compliance_percent'])->toEqual(50.0)
        ->and($groups[$this->teamA->id]['csat'])->toEqual(['responses' => 2, 'average' => 4.0, 'distribution' => ['1' => 0, '2' => 0, '3' => 1, '4' => 0, '5' => 1]])
        ->and($groups[$this->teamB->id]['tickets'])->toBe(1)
        ->and($groups[$this->teamB->id]['mttfr_minutes'])->toBeNull()
        ->and($groups[$this->teamB->id]['csat']['average'])->toBeNull();

    $byPriority = collect($this->getJson('/api/v1/esm/helpdesk/metrics?from=2027-06-01&to=2027-06-01&group_by=priority')->assertOk()->json('data.groups'))->keyBy('key');
    expect($byPriority->keys()->sort()->values()->all())->toBe(['high', 'low'])
        ->and($byPriority['high']['tickets'])->toBe(2)
        ->and($byPriority['low']['csat']['average'])->toEqual(3.0);

    $byContract = collect($this->getJson('/api/v1/esm/helpdesk/metrics?from=2027-06-01&to=2027-06-01&group_by=contract')->assertOk()->json('data.groups'))->keyBy(fn (array $group): string => (string) $group['key']);
    expect($byContract[$contract->id]['tickets'])->toBe(1)
        ->and($byContract[$contract->id]['csat']['average'])->toEqual(5.0)
        ->and($byContract['']['tickets'])->toBe(2);

    $this->getJson('/api/v1/esm/helpdesk/metrics?group_by=weather')->assertUnprocessable()->assertJsonValidationErrors('group_by');
});

test('the metrics are for service managers only', function (): void {
    Sanctum::actingAs(insightsMember($this->tenant, 'member'));

    $this->getJson('/api/v1/esm/helpdesk/metrics')->assertForbidden();
    $this->getJson('/api/v1/esm/helpdesk/breach-risk')->assertOk();
});

test('the customer panel shows contracts with their hours left, equipment, open invoices and the latest tickets', function (): void {
    $customer = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    $ticket = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id]);
    $earlier = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id, 'subject' => 'Earlier problem', 'created_at' => now()->subDays(3)]);
    Ticket::factory()->create(['tenant_id' => $this->tenant->id]);

    ServiceContract::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id, 'included_hours' => '10.00', 'consumed_hours' => '2.50']);
    ServiceContract::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id, 'included_hours' => '0.00']);
    ServiceContract::factory()->expired()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id, 'included_hours' => '99.00']);
    SerializedEquipmentPassport::factory()->create(['tenant_id' => $this->tenant->id, 'current_owner_customer_id' => $customer->id, 'serial_number' => 'SN-PANEL-1']);
    SerializedEquipmentPassport::factory()->create(['tenant_id' => $this->tenant->id, 'serial_number' => 'SN-OTHER']);

    $invoice = fn (array $attributes): SalesInvoice => SalesInvoice::factory()->create(['tenant_id' => $this->tenant->id, 'contact_id' => $customer->id, ...$attributes]);
    $invoice(['payment_status' => 'unpaid', 'total_gross' => 100.00, 'due_date' => now()->subDays(5)->toDateString()]);
    $invoice(['payment_status' => 'partially_paid', 'total_gross' => 50.50, 'due_date' => now()->addDays(5)->toDateString()]);
    $invoice(['payment_status' => 'paid', 'total_gross' => 999.00]);
    $invoice(['payment_status' => 'unpaid', 'invoice_type' => 'credit_note', 'total_gross' => 70.00]);

    $panel = $this->getJson("/api/v1/esm/tickets/{$ticket->id}/customer-context")->assertOk()->json('data');
    $contracts = collect($panel['contracts'])->keyBy('kind');

    expect($panel['customer_id'])->toBe($customer->id)
        ->and($panel['contracts'])->toHaveCount(2)
        ->and($contracts['prepaid_hours']['remaining_hours'])->toBe('7.50')
        ->and($contracts['flat_rate']['included_hours'])->toBe('0.00')
        ->and(array_column($panel['assets'], 'serial_number'))->toBe(['SN-PANEL-1'])
        ->and($panel['open_invoices'])->toBe(['count' => 2, 'overdue_count' => 1, 'total_gross' => '150.50'])
        ->and(array_column($panel['recent_tickets'], 'id'))->toBe([$earlier->id]);

    $foreign = Ticket::factory()->create(['tenant_id' => $this->other->id]);
    $this->getJson("/api/v1/esm/tickets/{$foreign->id}/customer-context")->assertNotFound();
});
