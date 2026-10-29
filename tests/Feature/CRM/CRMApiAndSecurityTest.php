<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\OpportunityStage;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'CRM API Test Tenant', 'legal_name' => 'CRM API Test Tenant SpA']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::firstOrCreate(
        ['email' => 'crmapiuser@example.com'],
        ['id' => (string) Str::uuid7(), 'name' => 'CRM API User', 'password' => bcrypt('secret')]
    );

    $this->actingAs($this->user);
});

test('lists and creates leads via API', function (): void {
    $response = $this->postJson('/api/v1/crm/leads', [
        'first_name' => 'Stefano',
        'last_name' => 'Brambilla',
        'company_name' => 'Brambilla Costruzioni SpA',
        'email' => 'stefano@brambilla.it',
        'phone' => '+39 02 112233',
        'title' => 'Chief Executive Officer',
        'estimated_budget' => 60000.00,
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('first_name', 'Stefano');
    $response->assertJsonPath('score', 90);

    $listResponse = $this->getJson('/api/v1/crm/leads');
    $listResponse->assertStatus(200);
    $listResponse->assertJsonStructure(['data', 'meta']);
});

test('lists and creates accounts and evaluates health via API', function (): void {
    $createResponse = $this->postJson('/api/v1/crm/accounts', [
        'name' => 'Titanium Software Srl',
        'tier' => 'enterprise',
        'industry' => 'Information Technology',
    ]);

    $createResponse->assertStatus(201);
    $accountId = $createResponse->json('id');

    $healthResponse = $this->getJson("/api/v1/crm/accounts/{$accountId}/health");
    $healthResponse->assertStatus(200);
    $healthResponse->assertJsonStructure(['id', 'account_id', 'health_index', 'churn_risk_indicator']);
});

test('lists and manages opportunities and stage progression via API', function (): void {
    $account = Account::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'account_number' => 'ACC-API-01',
        'name' => 'Mega Corp Srl',
    ]);

    $stageDisc = OpportunityStage::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'code' => 'discovery'],
        ['id' => (string) Str::uuid7(), 'name' => 'Discovery', 'default_probability' => 20.0, 'is_active' => true]
    );

    $stageWon = OpportunityStage::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'code' => 'closed_won'],
        ['id' => (string) Str::uuid7(), 'name' => 'Closed Won', 'default_probability' => 100.0, 'is_won' => true, 'is_active' => true]
    );

    $oppResponse = $this->postJson('/api/v1/crm/opportunities', [
        'account_id' => (string) $account->id,
        'name' => 'Big ERP Deal',
        'amount' => 75000.00,
        'currency' => 'EUR',
        'stage_code' => 'discovery',
    ]);

    $oppResponse->assertStatus(201);
    $oppId = $oppResponse->json('id');

    $stageResponse = $this->postJson("/api/v1/crm/opportunities/{$oppId}/stage", [
        'target_stage_code' => 'closed_won',
    ]);

    $stageResponse->assertStatus(200);
    $stageResponse->assertJsonPath('status', 'won');
    $stageResponse->assertJsonPath('probability', 100);
});

test('logs and completes activities via API', function (): void {
    $response = $this->postJson('/api/v1/crm/activities', [
        'subject' => 'Call Prospect',
        'type' => 'call',
        'priority' => 'high',
        'due_date' => now()->addDay()->toDateTimeString(),
    ]);

    $response->assertStatus(201);
    $activityId = $response->json('id');

    $completeResponse = $this->postJson("/api/v1/crm/activities/{$activityId}/complete", [
        'outcome' => 'Connected, demo scheduled for next Tuesday.',
    ]);

    $completeResponse->assertStatus(200);
    $completeResponse->assertJsonPath('status', 'completed');
});

test('enforces soft deletes and multi-tenant isolation', function (): void {
    $lead = Lead::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'first_name' => 'Luigi',
        'company_name' => 'Luigi Services',
        'status' => 'new',
    ]);

    $delResponse = $this->deleteJson("/api/v1/crm/leads/{$lead->id}");
    $delResponse->assertStatus(204);

    expect(Lead::where('id', $lead->id)->count())->toBe(0);
    expect(Lead::withTrashed()->where('id', $lead->id)->count())->toBe(1);

    // Other tenant should not see this lead
    $otherTenant = Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Foreign Tenant',
        'legal_name' => 'Foreign Tenant Ltd',
    ]);
    app(TenantContext::class)->setTenant($otherTenant);

    expect(Lead::where('id', $lead->id)->count())->toBe(0);
});
