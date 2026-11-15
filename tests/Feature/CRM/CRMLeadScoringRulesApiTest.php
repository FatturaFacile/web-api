<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadScoringRule;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->makeTenantAdmin = function (string $name, string $role = 'admin'): array {
        $tenant = Tenant::create(['name' => $name, 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
        $user = User::create(['name' => $name.' user', 'email' => Str::lower(Str::random(12)).'@scoring.test', 'password' => bcrypt('secret')]);
        $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

        return [$tenant, $user];
    };

    [$this->tenant, $this->admin] = ($this->makeTenantAdmin)('Scoring API');
    app(TenantContext::class)->setTenant($this->tenant);
    Sanctum::actingAs($this->admin);

    $this->rulePayload = [
        'code' => 'industry_manufacturing',
        'name' => 'Manufacturing industry',
        'category' => 'demographic',
        'field' => 'industry',
        'operator' => 'eq',
        'value' => 'manufacturing',
        'points' => 30,
        'is_active' => true,
    ];
});

test('creates, lists, shows, updates and deletes scoring rules', function (): void {
    $created = $this->postJson('/api/v1/crm/scoring-rules', $this->rulePayload)
        ->assertCreated()
        ->assertJsonPath('data.code', 'industry_manufacturing')
        ->assertJsonPath('data.points', 30)
        ->assertJsonPath('data.value', 'manufacturing');

    $id = $created->json('data.id');

    $this->getJson('/api/v1/crm/scoring-rules')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonStructure(['data' => [['id', 'code', 'name', 'category', 'field', 'event_type', 'operator', 'value', 'points', 'max_applications', 'decay_days', 'is_active']], 'meta' => ['current_page', 'total']]);

    $this->getJson("/api/v1/crm/scoring-rules/{$id}")->assertOk()->assertJsonPath('data.name', 'Manufacturing industry');

    $this->putJson("/api/v1/crm/scoring-rules/{$id}", ['points' => 45, 'is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.points', 45)
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.code', 'industry_manufacturing');

    $this->deleteJson("/api/v1/crm/scoring-rules/{$id}")->assertNoContent();
    $this->getJson("/api/v1/crm/scoring-rules/{$id}")->assertNotFound();
});

test('validates scoring rule payloads', function (): void {
    $this->postJson('/api/v1/crm/scoring-rules', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code', 'name', 'category', 'operator', 'points']);

    $this->postJson('/api/v1/crm/scoring-rules', array_merge($this->rulePayload, ['field' => 'password']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['field']);

    $this->postJson('/api/v1/crm/scoring-rules', array_merge($this->rulePayload, ['operator' => 'between', 'value' => 5]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['value']);

    $this->postJson('/api/v1/crm/scoring-rules', array_merge($this->rulePayload, ['category' => 'behavioral', 'field' => null, 'operator' => 'exists', 'value' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['event_type']);

    $this->postJson('/api/v1/crm/scoring-rules', $this->rulePayload)->assertCreated();
    $this->postJson('/api/v1/crm/scoring-rules', $this->rulePayload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

test('previews scores for a stored lead or for raw attributes without saving', function (): void {
    $this->postJson('/api/v1/crm/scoring-rules', $this->rulePayload)->assertCreated();
    $this->postJson('/api/v1/crm/scoring-rules', [
        'code' => 'big', 'name' => 'Big company', 'category' => 'demographic', 'field' => 'employee_count',
        'operator' => 'gte', 'value' => 100, 'points' => 25,
    ])->assertCreated();

    $preview = $this->postJson('/api/v1/crm/scoring-rules/preview', ['lead' => ['industry' => 'Manufacturing', 'employee_count' => 250]])
        ->assertOk()
        ->assertJsonPath('score', 55)
        ->assertJsonPath('rating', 'warm')
        ->assertJsonStructure(['score', 'rating', 'breakdown' => [['rule_id', 'code', 'points']]]);

    expect(collect($preview->json('breakdown'))->pluck('code')->all())->toEqualCanonicalizing(['industry_manufacturing', 'big']);

    app(TenantContext::class)->setTenant($this->tenant);
    $lead = Lead::create(['tenant_id' => (string) $this->tenant->id, 'industry' => 'manufacturing', 'status' => 'new', 'score' => 3]);

    $this->postJson('/api/v1/crm/scoring-rules/preview', ['lead_id' => (string) $lead->id])
        ->assertOk()
        ->assertJsonPath('score', 30);

    expect($lead->fresh()->score)->toBe(3);

    $this->postJson('/api/v1/crm/scoring-rules/preview', [])->assertUnprocessable();
});

test('reads and updates lead settings and rejects warm >= hot', function (): void {
    $this->getJson('/api/v1/crm/lead-settings')
        ->assertOk()
        ->assertJsonPath('data.warm_threshold', 40)
        ->assertJsonPath('data.hot_threshold', 70)
        ->assertJsonPath('data.auto_qualify_threshold', 80)
        ->assertJsonPath('data.dedup_mode', 'notify')
        ->assertJsonPath('data.dedup_levenshtein_max', 2)
        ->assertJsonPath('data.dedup_similarity_min', 0.85)
        ->assertJsonPath('data.default_country', 'IT');

    $this->putJson('/api/v1/crm/lead-settings', ['warm_threshold' => 20, 'hot_threshold' => 50, 'dedup_mode' => 'auto_merge', 'default_country' => 'ch'])
        ->assertOk()
        ->assertJsonPath('data.warm_threshold', 20)
        ->assertJsonPath('data.hot_threshold', 50)
        ->assertJsonPath('data.dedup_mode', 'auto_merge')
        ->assertJsonPath('data.default_country', 'CH');

    $this->putJson('/api/v1/crm/lead-settings', ['warm_threshold' => 60])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['warm_threshold']);

    $this->putJson('/api/v1/crm/lead-settings', ['warm_threshold' => 50, 'hot_threshold' => 50])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['warm_threshold']);

    $this->putJson('/api/v1/crm/lead-settings', ['dedup_mode' => 'delete_everything'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['dedup_mode']);
});

test('rescoring reflects rules and thresholds changed through the API', function (): void {
    app(TenantContext::class)->setTenant($this->tenant);
    $lead = Lead::create(['tenant_id' => (string) $this->tenant->id, 'industry' => 'manufacturing', 'status' => 'new', 'score' => 0, 'rating' => 'cold']);

    $this->postJson('/api/v1/crm/scoring-rules', $this->rulePayload)->assertCreated();

    $this->postJson("/api/v1/crm/leads/{$lead->id}/rescore")
        ->assertOk()
        ->assertJsonPath('data.score', 30)
        ->assertJsonPath('data.rating', 'cold')
        ->assertJsonPath('data.score_breakdown.0.code', 'industry_manufacturing');

    $this->putJson('/api/v1/crm/lead-settings', ['warm_threshold' => 10, 'hot_threshold' => 25])->assertOk();

    $this->postJson("/api/v1/crm/leads/{$lead->id}/rescore")
        ->assertOk()
        ->assertJsonPath('data.score', 30)
        ->assertJsonPath('data.rating', 'hot');

    app(TenantContext::class)->setTenant($this->tenant);
    $fresh = $lead->fresh();

    expect($fresh->score)->toBe(30)
        ->and($fresh->rating)->toBe('hot')
        ->and($fresh->score_breakdown[0]['points'])->toBe(30);
});

test('members can read but not change scoring configuration', function (): void {
    $member = User::create(['name' => 'Member', 'email' => Str::lower(Str::random(12)).'@scoring.test', 'password' => bcrypt('secret')]);
    $member->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    Sanctum::actingAs($member);

    $this->getJson('/api/v1/crm/scoring-rules')->assertOk();
    $this->getJson('/api/v1/crm/lead-settings')->assertOk();
    $this->postJson('/api/v1/crm/scoring-rules', $this->rulePayload)->assertForbidden();
    $this->putJson('/api/v1/crm/lead-settings', ['warm_threshold' => 10])->assertForbidden();
});

test('requires authentication', function (): void {
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/crm/scoring-rules')->assertUnauthorized();
});

test('isolates scoring rules and leads between tenants', function (): void {
    [$otherTenant, $otherAdmin] = ($this->makeTenantAdmin)('Other Scoring');
    app(TenantContext::class)->setTenant($otherTenant);
    $foreignRule = LeadScoringRule::create([
        'tenant_id' => (string) $otherTenant->id, 'code' => 'foreign', 'name' => 'Foreign', 'category' => 'demographic',
        'field' => 'industry', 'operator' => 'exists', 'points' => 10, 'is_active' => true,
    ]);
    $foreignLead = Lead::create(['tenant_id' => (string) $otherTenant->id, 'email' => 'foreign@lead.it', 'status' => 'new']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->getJson("/api/v1/crm/scoring-rules/{$foreignRule->id}")->assertNotFound();
    $this->putJson("/api/v1/crm/scoring-rules/{$foreignRule->id}", ['points' => 99])->assertNotFound();
    $this->deleteJson("/api/v1/crm/scoring-rules/{$foreignRule->id}")->assertNotFound();
    $this->postJson("/api/v1/crm/leads/{$foreignLead->id}/rescore")->assertNotFound();
    $this->postJson('/api/v1/crm/scoring-rules/preview', ['lead_id' => (string) $foreignLead->id])->assertNotFound();
    $this->getJson('/api/v1/crm/scoring-rules')->assertOk()->assertJsonPath('meta.total', 0);

    // Asking explicitly for the other tenant is refused by the tenant middleware.
    $this->withHeader('X-Tenant-Id', (string) $otherTenant->id)->getJson('/api/v1/crm/scoring-rules')->assertForbidden();
});
