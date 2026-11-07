<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceManagement\Models\CSATSurvey;
use Modules\ServiceManagement\Models\ServiceCategory;
use Modules\ServiceManagement\Models\ServiceTeam;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\Ticket;

uses(RefreshDatabase::class);

function helpdeskReportMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => 'User', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

beforeEach(function (): void {
    $this->travelTo('2027-05-10 12:00:00');
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    Ticket::factory()->create(['tenant_id' => $this->other->id, 'created_at' => '2027-05-05 10:00:00', 'source' => 'email', 'status' => 'open']);

    $team = ServiceTeam::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Service desk']);
    $this->alice = ServiceTechnician::factory()->create(['tenant_id' => $this->tenant->id, 'team_id' => $team->id, 'first_name' => 'Alice', 'last_name' => 'A']);
    $this->bob = ServiceTechnician::factory()->create(['tenant_id' => $this->tenant->id, 'team_id' => $team->id, 'first_name' => 'Bob', 'last_name' => 'B']);
    $category = ServiceCategory::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Hardware']);
    $this->category = $category;

    $base = ['tenant_id' => $this->tenant->id, 'assigned_team_id' => $team->id, 'metadata' => []];
    $first = Ticket::factory()->create([...$base, 'created_at' => '2027-05-01 10:00:00', 'response_due_at' => '2027-05-01 11:00:00', 'first_responded_at' => '2027-05-01 10:30:00', 'resolution_due_at' => '2027-05-02 10:00:00', 'resolved_at' => '2027-05-01 12:00:00', 'status' => 'resolved', 'source' => 'email', 'category_id' => $category->id, 'assigned_technician_id' => $this->alice->id]);
    $second = Ticket::factory()->create([...$base, 'created_at' => '2027-05-02 10:00:00', 'response_due_at' => '2027-05-02 11:00:00', 'first_responded_at' => '2027-05-02 12:00:00', 'resolution_due_at' => '2027-05-02 18:00:00', 'resolved_at' => '2027-05-03 10:00:00', 'status' => 'closed', 'source' => 'portal', 'category_id' => $category->id, 'assigned_technician_id' => $this->alice->id]);
    $third = Ticket::factory()->create([...$base, 'created_at' => '2027-05-09 12:00:00', 'response_due_at' => '2027-05-09 13:00:00', 'first_responded_at' => null, 'resolution_due_at' => '2027-05-20 13:00:00', 'resolved_at' => null, 'status' => 'open', 'source' => 'email', 'category_id' => null, 'assigned_technician_id' => $this->bob->id]);
    Ticket::factory()->create([...$base, 'created_at' => '2027-04-01 10:00:00', 'status' => 'open', 'source' => 'phone', 'resolved_at' => null, 'assigned_technician_id' => null]);
    Ticket::factory()->create([...$base, 'created_at' => '2027-05-05 10:00:00', 'status' => 'closed', 'source' => 'email', 'merged_into_ticket_id' => $third->id, 'resolved_at' => '2027-05-05 11:00:00']);

    CSATSurvey::factory()->create(['tenant_id' => $this->tenant->id, 'ticket_id' => $first->id, 'score' => 5, 'status' => 'completed', 'responded_at' => '2027-05-02 09:00:00']);
    CSATSurvey::factory()->create(['tenant_id' => $this->tenant->id, 'ticket_id' => $second->id, 'score' => 2, 'status' => 'completed', 'responded_at' => '2027-05-04 09:00:00']);

    $this->team = $team;
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
});

test('the report gives SLA compliance, response and resolution percentiles, backlog, volume, workload and CSAT for the tenant', function (): void {
    Sanctum::actingAs(helpdeskReportMember($this->tenant, 'service_manager'));

    $report = $this->getJson('/api/v1/esm/helpdesk/reports?from=2027-05-01&to=2027-05-10')->assertOk()->json('data');

    expect($report['period'])->toBe(['from' => '2027-05-01', 'to' => '2027-05-10'])
        ->and($report['sla']['first_response'])->toBe(['tickets' => 3, 'met' => 1, 'breached' => 2, 'compliance_percent' => 33.33])
        ->and($report['sla']['resolution'])->toBe(['tickets' => 2, 'met' => 1, 'breached' => 1, 'compliance_percent' => 50])
        ->and($report['first_response_minutes'])->toBe(['count' => 2, 'mean' => 75, 'p50' => 75, 'p90' => 111])
        ->and($report['resolution_minutes'])->toBe(['count' => 2, 'mean' => 780, 'p50' => 780, 'p90' => 1308])
        ->and($report['backlog']['by_status'])->toBe(['open' => 2])
        ->and($report['backlog']['by_age'])->toBe(['under_1_day' => 0, '1_to_3_days' => 1, '3_to_7_days' => 0, '7_to_30_days' => 0, 'over_30_days' => 1])
        ->and($report['volume']['by_channel'])->toEqualCanonicalizing(['email' => 2, 'portal' => 1])
        ->and($report['volume']['by_category'][0])->toBe(['category_id' => $this->category->id, 'name' => 'Hardware', 'tickets' => 2])
        ->and(collect($report['agent_workload'])->keyBy('name')->map(fn (array $row): array => [$row['open_tickets'], $row['resolved_in_period']])->all())->toBe(['Bob B' => [1, 0], 'Alice A' => [0, 2]])
        ->and($report['csat_by_team'])->toBe([['team_id' => $this->team->id, 'name' => 'Service desk', 'responses' => 2, 'mean_score' => 3.5, 'satisfied_percent' => 50]]);
});

test('agents cannot read the report and another company sees only its own figures', function (): void {
    Sanctum::actingAs(helpdeskReportMember($this->tenant, 'technician'));
    $this->getJson('/api/v1/esm/helpdesk/reports')->assertForbidden();

    Sanctum::actingAs(helpdeskReportMember($this->other, 'admin'));
    $report = $this->withHeader('X-Tenant-Id', $this->other->id)->getJson('/api/v1/esm/helpdesk/reports?from=2027-05-01&to=2027-05-10')->assertOk()->json('data');

    expect($report['volume']['total'])->toBe(1)
        ->and($report['agent_workload'])->toBe([])
        ->and($report['csat_by_team'])->toBe([]);

    $this->getJson('/api/v1/esm/helpdesk/reports?from=2027-05-10&to=2027-05-01')->assertUnprocessable();
});
