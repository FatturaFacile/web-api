<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadRoutingRule;
use Modules\CRM\Notifications\LeadAssignedNotification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();

    $this->tenant = Tenant::create(['name' => 'Routing API', 'legal_name' => 'Routing API SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->makeUser = function (string $role = 'member', ?Tenant $tenant = null): User {
        $user = User::create(['name' => 'Rep '.Str::random(4), 'email' => Str::lower(Str::random(12)).'@routing-api.test', 'password' => bcrypt('secret')]);
        $user->tenants()->attach(($tenant ?? $this->tenant)->id, ['id' => (string) Str::uuid(), 'role' => $role]);

        return $user;
    };

    $this->admin = ($this->makeUser)('admin');
    $this->reps = [($this->makeUser)(), ($this->makeUser)(), ($this->makeUser)()];
    Sanctum::actingAs($this->admin);

    $this->rulePayload = fn (array $overrides = []): array => array_merge([
        'name' => 'Ticino ERP',
        'priority' => 10,
        'conditions' => ['countries' => ['CH'], 'regions' => ['TI'], 'product_lines' => ['ERP'], 'employee_min' => 10, 'employee_max' => 500, 'ratings' => ['warm', 'hot']],
        'strategy' => 'round_robin',
        'assignee_user_ids' => array_map(fn (User $user): string => (string) $user->id, $this->reps),
        'is_default' => false,
        'is_active' => true,
    ], $overrides);
});

test('creates, lists, shows, updates and deletes routing rules', function (): void {
    $created = $this->postJson('/api/v1/crm/routing-rules', ($this->rulePayload)())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Ticino ERP')
        ->assertJsonPath('data.conditions.regions', ['TI'])
        ->assertJsonPath('data.conditions.employee_max', 500)
        ->assertJsonPath('data.strategy', 'round_robin')
        ->assertJsonCount(3, 'data.assignee_user_ids');

    $id = $created->json('data.id');

    $this->getJson('/api/v1/crm/routing-rules')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonStructure(['data' => [['id', 'name', 'priority', 'conditions' => ['countries', 'regions', 'product_lines', 'employee_min', 'employee_max', 'ratings'], 'strategy', 'assignee_user_ids', 'is_default', 'is_active']], 'meta' => ['current_page', 'total']]);

    $this->getJson("/api/v1/crm/routing-rules/{$id}")->assertOk()->assertJsonPath('data.id', $id);

    $this->putJson("/api/v1/crm/routing-rules/{$id}", ['strategy' => 'fixed', 'assignee_user_ids' => [(string) $this->reps[0]->id]])
        ->assertOk()
        ->assertJsonPath('data.strategy', 'fixed')
        ->assertJsonPath('data.assignee_user_ids', [(string) $this->reps[0]->id])
        ->assertJsonPath('data.name', 'Ticino ERP');

    $this->deleteJson("/api/v1/crm/routing-rules/{$id}")->assertNoContent();
    $this->getJson("/api/v1/crm/routing-rules/{$id}")->assertNotFound();
});

test('validates routing rules and refuses assignees outside the tenant', function (): void {
    $outsiderTenant = Tenant::create(['name' => 'Outsider', 'legal_name' => 'Outsider Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $outsider = ($this->makeUser)('member', $outsiderTenant);

    $this->postJson('/api/v1/crm/routing-rules', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'strategy', 'assignee_user_ids']);

    $this->postJson('/api/v1/crm/routing-rules', ($this->rulePayload)(['assignee_user_ids' => [(string) $outsider->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assignee_user_ids.0']);

    $this->postJson('/api/v1/crm/routing-rules', ($this->rulePayload)(['strategy' => 'random']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['strategy']);

    $this->postJson('/api/v1/crm/routing-rules', ($this->rulePayload)(['conditions' => ['employee_min' => 100, 'employee_max' => 10]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['conditions.employee_max']);
});

test('reorders routing rules', function (): void {
    $first = $this->postJson('/api/v1/crm/routing-rules', ($this->rulePayload)(['name' => 'First', 'priority' => 1]))->json('data.id');
    $second = $this->postJson('/api/v1/crm/routing-rules', ($this->rulePayload)(['name' => 'Second', 'priority' => 2]))->json('data.id');

    $this->postJson('/api/v1/crm/routing-rules/reorder', ['ids' => [$second, $first]])
        ->assertOk()
        ->assertJsonPath('data.0.id', $second)
        ->assertJsonPath('data.0.priority', 10)
        ->assertJsonPath('data.1.id', $first)
        ->assertJsonPath('data.1.priority', 20);

    $this->postJson('/api/v1/crm/routing-rules/reorder', ['ids' => [(string) Str::uuid7()]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ids']);
});

test('routes a lead on demand and spreads matching leads evenly', function (): void {
    $this->postJson('/api/v1/crm/routing-rules', ($this->rulePayload)())->assertCreated();

    app(TenantContext::class)->setTenant($this->tenant);
    $leads = collect(range(1, 6))->map(fn (int $i): Lead => Lead::create([
        'tenant_id' => (string) $this->tenant->id,
        'email' => "lead{$i}@ticino.ch",
        'country' => 'CH',
        'state_region' => 'TI',
        'product_line' => 'erp',
        'employee_count' => 50,
        'rating' => 'warm',
        'status' => 'new',
    ]));

    foreach ($leads as $lead) {
        $this->postJson("/api/v1/crm/leads/{$lead->id}/route")
            ->assertOk()
            ->assertJsonPath('routing.default_rule', false)
            ->assertJsonStructure(['data' => ['id', 'assigned_user_id'], 'routing' => ['assigned_user_id', 'rule_id', 'default_rule']]);
    }

    app(TenantContext::class)->setTenant($this->tenant);
    $counts = Lead::whereIn('id', $leads->pluck('id'))->get()->countBy('assigned_user_id');

    foreach ($this->reps as $rep) {
        expect($counts[(string) $rep->id] ?? 0)->toBe(2);
        Notification::assertSentToTimes($rep, LeadAssignedNotification::class, 2);
    }

    $nonMatching = Lead::create(['tenant_id' => (string) $this->tenant->id, 'email' => 'zurich@lead.ch', 'country' => 'CH', 'state_region' => 'ZH', 'status' => 'new']);
    $this->postJson("/api/v1/crm/leads/{$nonMatching->id}/route")
        ->assertOk()
        ->assertJsonPath('routing.assigned_user_id', null);

    Notification::assertSentTo($this->admin, LeadAssignedNotification::class, fn (LeadAssignedNotification $notification): bool => $notification->unassigned);
});

test('isolates routing rules between tenants', function (): void {
    $otherTenant = Tenant::create(['name' => 'Other Routing', 'legal_name' => 'Other Routing Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $otherRep = ($this->makeUser)('member', $otherTenant);
    app(TenantContext::class)->setTenant($otherTenant);
    $foreign = LeadRoutingRule::create([
        'tenant_id' => (string) $otherTenant->id, 'name' => 'Foreign', 'priority' => 1, 'strategy' => 'fixed',
        'assignee_user_ids' => [(string) $otherRep->id], 'is_default' => true, 'is_active' => true,
    ]);
    $foreignLead = Lead::create(['tenant_id' => (string) $otherTenant->id, 'email' => 'foreign@lead.it', 'status' => 'new']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->getJson("/api/v1/crm/routing-rules/{$foreign->id}")->assertNotFound();
    $this->putJson("/api/v1/crm/routing-rules/{$foreign->id}", ['name' => 'Hacked'])->assertNotFound();
    $this->deleteJson("/api/v1/crm/routing-rules/{$foreign->id}")->assertNotFound();
    $this->postJson("/api/v1/crm/leads/{$foreignLead->id}/route")->assertNotFound();
    $this->postJson('/api/v1/crm/routing-rules/reorder', ['ids' => [(string) $foreign->id]])->assertUnprocessable();

    expect(LeadRoutingRule::withoutGlobalScopes()->find($foreign->id)?->name)->toBe('Foreign');
});
