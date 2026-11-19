<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadActivityEvent;
use Modules\CRM\Models\LeadDuplicateCandidate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();

    $this->tenant = Tenant::create(['name' => 'Duplicates API', 'legal_name' => 'Duplicates API Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::create(['name' => 'Rep', 'email' => Str::lower(Str::random(12)).'@dups.test', 'password' => bcrypt('secret')]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'sales']);
    Sanctum::actingAs($this->user);

    $this->original = Lead::create([
        'tenant_id' => (string) $this->tenant->id,
        'first_name' => 'Carla',
        'last_name' => 'Fontana',
        'email' => 'carla@fontana.it',
        'company_name' => 'Fontana Srl',
        'status' => 'new',
        'tags' => ['fair-2026'],
    ]);
    $this->incoming = Lead::create([
        'tenant_id' => (string) $this->tenant->id,
        'first_name' => 'Carla',
        'email' => 'carla@fontana.it',
        'phone' => '333 000 1111',
        'title' => 'CEO',
        'company_name' => 'Fontana Group Srl',
        'status' => 'new',
        'tags' => ['webinar'],
    ]);
    LeadActivityEvent::create(['tenant_id' => (string) $this->tenant->id, 'lead_id' => $this->incoming->id, 'type' => 'site_visit', 'occurred_at' => now()]);

    $this->candidate = LeadDuplicateCandidate::create([
        'tenant_id' => (string) $this->tenant->id,
        'lead_id' => $this->incoming->id,
        'candidate_lead_id' => $this->original->id,
        'match_type' => 'email',
        'confidence' => 1,
        'status' => 'pending',
    ]);
});

test('lists pending duplicates with lead, candidate summary, match type and confidence', function (): void {
    $this->getJson('/api/v1/crm/duplicates?status=pending')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', (string) $this->candidate->id)
        ->assertJsonPath('data.0.lead.id', (string) $this->incoming->id)
        ->assertJsonPath('data.0.candidate.type', 'lead')
        ->assertJsonPath('data.0.candidate.id', (string) $this->original->id)
        ->assertJsonPath('data.0.candidate.summary', 'Carla Fontana - Fontana Srl <carla@fontana.it>')
        ->assertJsonPath('data.0.match_type', 'email')
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonStructure(['data' => [['id', 'lead', 'candidate' => ['type', 'id', 'summary'], 'match_type', 'confidence', 'status']], 'meta' => ['current_page', 'total']]);

    $this->getJson('/api/v1/crm/duplicates?status=dismissed')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/crm/duplicates?status=bogus')->assertUnprocessable();
});

test('merges into the candidate lead honouring field choices', function (): void {
    $response = $this->postJson("/api/v1/crm/duplicates/{$this->candidate->id}/merge", [
        'survivor' => 'candidate',
        'field_choices' => ['company_name' => 'lead'],
    ])->assertOk()
        ->assertJsonPath('data.status', 'merged')
        ->assertJsonPath('data.survivor_lead.id', (string) $this->original->id)
        ->assertJsonPath('data.merged_lead_id', (string) $this->incoming->id);

    app(TenantContext::class)->setTenant($this->tenant);
    $survivor = $this->original->fresh();
    $merged = $this->incoming->fresh();

    expect($survivor->last_name)->toBe('Fontana')
        ->and($survivor->company_name)->toBe('Fontana Group Srl')
        ->and($survivor->phone)->toBe('333 000 1111')
        ->and($survivor->title)->toBe('CEO')
        ->and($survivor->tags)->toEqualCanonicalizing(['fair-2026', 'webinar'])
        ->and($merged->merged_into_lead_id)->toBe((string) $survivor->id)
        ->and($merged->status)->toBe('archived')
        ->and(LeadActivityEvent::where('lead_id', $survivor->id)->count())->toBe(1)
        ->and($this->candidate->fresh()->status)->toBe('merged')
        ->and($this->candidate->fresh()->resolved_by)->toBe((string) $this->user->id)
        ->and(TransactionalOutbox::where('event_type', 'crm.lead.merged')->where('aggregate_id', (string) $survivor->id)->exists())->toBeTrue();

    expect($response->json('data.survivor_lead.company_name'))->toBe('Fontana Group Srl');

    $this->postJson("/api/v1/crm/duplicates/{$this->candidate->id}/merge", ['survivor' => 'lead'])
        ->assertStatus(409);
});

test('merges into the new lead keeping its values by default', function (): void {
    $this->postJson("/api/v1/crm/duplicates/{$this->candidate->id}/merge", ['survivor' => 'lead'])->assertOk();

    app(TenantContext::class)->setTenant($this->tenant);
    $survivor = $this->incoming->fresh();

    expect($survivor->company_name)->toBe('Fontana Group Srl')
        ->and($survivor->last_name)->toBe('Fontana')
        ->and($this->original->fresh()->merged_into_lead_id)->toBe((string) $survivor->id);
});

test('validates merge requests', function (): void {
    $this->postJson("/api/v1/crm/duplicates/{$this->candidate->id}/merge", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['survivor']);

    $this->postJson("/api/v1/crm/duplicates/{$this->candidate->id}/merge", ['survivor' => 'lead', 'field_choices' => ['tenant_id' => 'candidate']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['field_choices.tenant_id']);
});

test('dismisses a duplicate and refuses to resolve it twice', function (): void {
    $this->postJson("/api/v1/crm/duplicates/{$this->candidate->id}/dismiss")
        ->assertOk()
        ->assertJsonPath('data.status', 'dismissed')
        ->assertJsonPath('data.resolved_by', (string) $this->user->id);

    $this->postJson("/api/v1/crm/duplicates/{$this->candidate->id}/dismiss")->assertStatus(409);
    $this->postJson("/api/v1/crm/duplicates/{$this->candidate->id}/merge", ['survivor' => 'lead'])->assertStatus(409);

    app(TenantContext::class)->setTenant($this->tenant);
    expect($this->incoming->fresh()->merged_into_lead_id)->toBeNull();
});

test('refuses to merge a contact candidate', function (): void {
    $contact = Contact::create(['tenant_id' => (string) $this->tenant->id, 'type' => 'client', 'country' => 'IT', 'company_name' => 'Fontana Srl', 'email' => 'carla@fontana.it']);
    $contactCandidate = LeadDuplicateCandidate::create([
        'tenant_id' => (string) $this->tenant->id,
        'lead_id' => $this->incoming->id,
        'contact_id' => $contact->id,
        'match_type' => 'email',
        'confidence' => 1,
        'status' => 'pending',
    ]);

    $this->getJson('/api/v1/crm/duplicates')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->postJson("/api/v1/crm/duplicates/{$contactCandidate->id}/merge", ['survivor' => 'lead'])
        ->assertUnprocessable()
        ->assertJsonStructure(['message']);
});

test('isolates duplicates between tenants', function (): void {
    $otherTenant = Tenant::create(['name' => 'Other Dups', 'legal_name' => 'Other Dups Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($otherTenant);
    $a = Lead::create(['tenant_id' => (string) $otherTenant->id, 'email' => 'x@other.it', 'status' => 'new']);
    $b = Lead::create(['tenant_id' => (string) $otherTenant->id, 'email' => 'x@other.it', 'status' => 'new']);
    $foreign = LeadDuplicateCandidate::create([
        'tenant_id' => (string) $otherTenant->id, 'lead_id' => $a->id, 'candidate_lead_id' => $b->id,
        'match_type' => 'email', 'confidence' => 1, 'status' => 'pending',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->getJson('/api/v1/crm/duplicates')->assertOk()->assertJsonPath('meta.total', 1);
    $this->postJson("/api/v1/crm/duplicates/{$foreign->id}/merge", ['survivor' => 'lead'])->assertNotFound();
    $this->postJson("/api/v1/crm/duplicates/{$foreign->id}/dismiss")->assertNotFound();
});
