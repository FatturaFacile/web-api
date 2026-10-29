<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\ProjectDatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBudget;
use Modules\Projects\Models\ProjectChangeOrder;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Models\ProjectTask;
use Modules\Projects\Models\ProjectTimesheet;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    Gate::before(fn () => true);

    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Project Test Tenant',
        'legal_name' => 'Project Enterprise Engineering SpA',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::first() ?? User::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'name' => 'Senior Project Lead',
        'email' => 'lead@projects.enterprise.test',
        'password' => bcrypt('password123'),
    ]);

    DB::table('tenant_user')->updateOrInsert(
        ['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id],
        [
            'id' => (string) Str::uuid7(),
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );

    $this->contact = Contact::where('tenant_id', $this->tenant->id)->first() ?? Contact::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'type' => 'customer',
        'country' => 'IT',
        'company_name' => 'Primary Client S.p.A.',
        'email' => 'client@primary.it',
        'vat_number' => 'IT11223344556',
    ]);

    $this->withHeader('X-Tenant-ID', $this->tenant->id);

    Sanctum::actingAs($this->user);
});

test('project idempotent seeders execute cleanly and verify 5 archetypes', function (): void {
    $this->seed(ProjectDatabaseSeeder::class);

    expect(Project::where('tenant_id', $this->tenant->id)->count())->toBeGreaterThanOrEqual(5);

    $construction = Project::where('project_number', 'PRJ-CON-2027-01')->first();
    expect($construction)->not->toBeNull()
        ->and($construction->project_type)->toBe('construction')
        ->and((float) $construction->contract_value)->toBe(750000.00);

    $erp = Project::where('project_number', 'PRJ-ERP-2027-02')->first();
    expect($erp)->not->toBeNull()
        ->and($erp->project_type)->toBe('software_dev');
});

test('can create project through rest api and receive 201', function (): void {
    $payload = [
        'contact_id' => $this->contact->id,
        'project_number' => 'PRJ-REST-'.rand(1000, 9999),
        'name' => 'Subsea Cable Route Survey',
        'project_type' => 'construction',
        'billing_method' => 'fixed_price',
        'planned_start_date' => now()->toDateString(),
        'planned_end_date' => now()->addMonths(4)->toDateString(),
        'project_manager_user_id' => $this->user->id,
        'contract_value' => 180000.00,
        'description' => 'Geophysical sonar imaging and bathymetric mapping',
    ];

    $response = $this->postJson('/api/v1/projects', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Subsea Cable Route Survey')
        ->assertJsonPath('data.contract_value', 180000);

    $this->assertDatabaseHas('projects', [
        'tenant_id' => $this->tenant->id,
        'project_number' => $payload['project_number'],
    ]);
});

test('can list and filter projects with pagination', function (): void {
    Project::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);

    $response = $this->getJson('/api/v1/projects?per_page=2');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'total'],
        ]);
});

test('can show and update project', function (): void {
    $project = Project::factory()->create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->contact->id,
        'name' => 'Initial Title',
    ]);

    $showResponse = $this->getJson("/api/v1/projects/{$project->id}");
    $showResponse->assertStatus(200)
        ->assertJsonPath('data.id', $project->id);

    $updateResponse = $this->putJson("/api/v1/projects/{$project->id}", [
        'name' => 'Updated Modernized Title',
        'status' => 'active',
    ]);

    $updateResponse->assertStatus(200)
        ->assertJsonPath('data.name', 'Updated Modernized Title')
        ->assertJsonPath('data.status', 'active');
});

test('can verify all project factory states', function (): void {
    $draft = Project::factory()->draft()->create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id]);
    expect($draft->status)->toBe('draft');

    $active = Project::factory()->active()->create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id]);
    expect($active->status)->toBe('active');

    $completed = Project::factory()->completed()->create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id]);
    expect($completed->status)->toBe('completed');

    $closed = Project::factory()->closed()->create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id]);
    expect($closed->status)->toBe('closed');
});

test('can create related factory entities for task, budget, cost, timesheet, change order', function (): void {
    $project = Project::factory()->create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id]);

    $task = ProjectTask::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id]);
    expect($task->project_id)->toBe($project->id);

    $budget = ProjectBudget::factory()->approved()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id]);
    expect($budget->status)->toBe('approved');

    $costEntry = ProjectCostEntry::factory()->material()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id]);
    expect($costEntry->cost_category)->toBe('material');

    $timesheet = ProjectTimesheet::factory()->approved()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id]);
    expect($timesheet->status)->toBe('approved');

    $changeOrder = ProjectChangeOrder::factory()->approved()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id]);
    expect($changeOrder->status)->toBe('customer_approved');
});
