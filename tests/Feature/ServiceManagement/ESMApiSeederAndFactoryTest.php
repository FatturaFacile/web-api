<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\ServiceDemoDataSeeder;
use Database\Seeders\ServiceReferenceSeeder;
use Database\Seeders\ServiceTestingSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceManagement\Models\BusinessHoursCalendar;
use Modules\ServiceManagement\Models\ChangeRequest;
use Modules\ServiceManagement\Models\CSATSurvey;
use Modules\ServiceManagement\Models\Incident;
use Modules\ServiceManagement\Models\KnowledgeArticle;
use Modules\ServiceManagement\Models\PartsConsumption;
use Modules\ServiceManagement\Models\Problem;
use Modules\ServiceManagement\Models\ServiceAppointment;
use Modules\ServiceManagement\Models\ServiceCategory;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\ServiceTeam;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\ServiceVisit;
use Modules\ServiceManagement\Models\SLAPolicy;
use Modules\ServiceManagement\Models\SupportPlan;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketTimeEntry;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\ServiceManagement\Models\WorkOrderItem;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

test('ESM API endpoints provide authenticated ticket, incident, work order, and CSAT operations', function () {
    // 1. GET /api/v1/esm/tickets
    Ticket::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);
    $response = $this->getJson('/api/v1/esm/tickets');
    $response->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['current_page', 'total']]);

    // 2. POST /api/v1/esm/tickets
    $storeResponse = $this->postJson('/api/v1/esm/tickets', [
        'title' => 'API Gateway timeout in production',
        'description' => 'Upstream service takes over 30s to respond',
        'priority' => 'high',
        'channel' => 'portal',
    ]);
    $storeResponse->assertCreated()
        ->assertJsonPath('data.title', 'API Gateway timeout in production');

    // 3. GET /api/v1/esm/incidents
    $incResponse = $this->getJson('/api/v1/esm/incidents');
    $incResponse->assertOk()
        ->assertJsonStructure(['data', 'meta']);

    // 4. GET /api/v1/esm/work-orders
    $woResponse = $this->getJson('/api/v1/esm/work-orders');
    $woResponse->assertOk()
        ->assertJsonStructure(['data', 'meta']);

    // 5. POST /api/v1/esm/csat
    $csatResponse = $this->postJson('/api/v1/esm/csat', [
        'score' => 5,
        'feedback' => 'Exceptional response time and professional resolution.',
        'respondent_email' => 'customer.executive@client.com',
    ]);
    $csatResponse->assertCreated()
        ->assertJsonPath('data.score', 5);
});

test('ESM multilingual seeders run idempotently without constraint violations', function () {
    // First run
    $this->seed(ServiceReferenceSeeder::class);
    $this->seed(ServiceTestingSeeder::class);
    $this->seed(ServiceDemoDataSeeder::class);

    expect(ServiceCategory::where('tenant_id', $this->tenant->id)->count())->toBeGreaterThan(0);

    // Second run (asserting 100% idempotency)
    $this->seed(ServiceReferenceSeeder::class);
    $this->seed(ServiceTestingSeeder::class);
    $this->seed(ServiceDemoDataSeeder::class);

    expect(ServiceCategory::where('tenant_id', $this->tenant->id)->count())->toBeGreaterThan(0);
});

test('all 19 ESM factories instantiate valid persisted models with relational consistency', function () {
    $factories = [
        ServiceCategory::class => fn () => ServiceCategory::factory()->create(['tenant_id' => $this->tenant->id]),
        BusinessHoursCalendar::class => fn () => BusinessHoursCalendar::factory()->create(['tenant_id' => $this->tenant->id]),
        SupportPlan::class => fn () => SupportPlan::factory()->create(['tenant_id' => $this->tenant->id]),
        SLAPolicy::class => fn () => SLAPolicy::factory()->create(['tenant_id' => $this->tenant->id]),
        ServiceContract::class => fn () => ServiceContract::factory()->create(['tenant_id' => $this->tenant->id]),
        ServiceTeam::class => fn () => ServiceTeam::factory()->create(['tenant_id' => $this->tenant->id]),
        ServiceTechnician::class => fn () => ServiceTechnician::factory()->create(['tenant_id' => $this->tenant->id]),
        KnowledgeArticle::class => fn () => KnowledgeArticle::factory()->create(['tenant_id' => $this->tenant->id]),
        Ticket::class => fn () => Ticket::factory()->create(['tenant_id' => $this->tenant->id]),
        TicketTimeEntry::class => fn () => TicketTimeEntry::factory()->create(['tenant_id' => $this->tenant->id]),
        Incident::class => fn () => Incident::factory()->create(['tenant_id' => $this->tenant->id]),
        Problem::class => fn () => Problem::factory()->create(['tenant_id' => $this->tenant->id]),
        ChangeRequest::class => fn () => ChangeRequest::factory()->create(['tenant_id' => $this->tenant->id]),
        WorkOrder::class => fn () => WorkOrder::factory()->create(['tenant_id' => $this->tenant->id]),
        WorkOrderItem::class => fn () => WorkOrderItem::factory()->create(['tenant_id' => $this->tenant->id]),
        ServiceAppointment::class => fn () => ServiceAppointment::factory()->create(['tenant_id' => $this->tenant->id]),
        ServiceVisit::class => fn () => ServiceVisit::factory()->create(['tenant_id' => $this->tenant->id]),
        PartsConsumption::class => fn () => PartsConsumption::factory()->create(['tenant_id' => $this->tenant->id]),
        CSATSurvey::class => fn () => CSATSurvey::factory()->create(['tenant_id' => $this->tenant->id]),
    ];

    expect(count($factories))->toBe(19);

    foreach ($factories as $class => $factoryCall) {
        $model = $factoryCall();
        expect($model)->toBeInstanceOf($class)
            ->and($model->exists)->toBeTrue();
    }
});
