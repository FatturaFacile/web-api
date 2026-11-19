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
    $this->tenant = Tenant::create(['name' => 'Events API', 'legal_name' => 'Events API Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::create(['name' => 'Rep', 'email' => Str::lower(Str::random(12)).'@events.test', 'password' => bcrypt('secret')]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'sales']);
    Sanctum::actingAs($this->user);

    LeadScoringRule::create([
        'tenant_id' => (string) $this->tenant->id,
        'code' => 'opens',
        'name' => 'Email opens',
        'category' => 'behavioral',
        'event_type' => 'email_opened',
        'operator' => 'exists',
        'points' => 15,
        'max_applications' => 2,
        'decay_days' => 30,
        'is_active' => true,
    ]);

    $this->lead = Lead::create(['tenant_id' => (string) $this->tenant->id, 'email' => 'events@lead.it', 'status' => 'new', 'score' => 0, 'rating' => 'cold']);
});

test('records an event, re-scores the lead and lists events', function (): void {
    $this->postJson("/api/v1/crm/leads/{$this->lead->id}/events", [
        'type' => 'email_opened',
        'occurred_at' => now()->subDay()->toIso8601String(),
        'metadata' => ['campaign' => 'spring'],
        'external_id' => 'mail-1',
    ])->assertCreated()
        ->assertJsonPath('data.type', 'email_opened')
        ->assertJsonPath('data.metadata.campaign', 'spring')
        ->assertJsonPath('lead.score', 15)
        ->assertJsonPath('lead.rating', 'cold');

    $this->postJson("/api/v1/crm/leads/{$this->lead->id}/events", ['type' => 'email_opened'])->assertCreated()->assertJsonPath('lead.score', 30);
    $this->postJson("/api/v1/crm/leads/{$this->lead->id}/events", ['type' => 'email_opened'])->assertCreated()->assertJsonPath('lead.score', 30);

    $this->getJson("/api/v1/crm/leads/{$this->lead->id}/events")
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonStructure(['data' => [['id', 'lead_id', 'type', 'occurred_at', 'metadata', 'external_id']], 'meta' => ['current_page', 'total']]);

    app(TenantContext::class)->setTenant($this->tenant);
    $fresh = $this->lead->fresh();

    expect($fresh->score)->toBe(30)
        ->and($fresh->last_activity_at)->not->toBeNull()
        ->and($fresh->score_breakdown[0]['applications'])->toBe(2);
});

test('is idempotent on external_id', function (): void {
    $first = $this->postJson("/api/v1/crm/leads/{$this->lead->id}/events", ['type' => 'email_opened', 'external_id' => 'dup-1'])->assertCreated();

    $this->postJson("/api/v1/crm/leads/{$this->lead->id}/events", ['type' => 'email_opened', 'external_id' => 'dup-1'])
        ->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'));

    $this->getJson("/api/v1/crm/leads/{$this->lead->id}/events")->assertJsonPath('meta.total', 1);
});

test('validates event payloads', function (): void {
    $this->postJson("/api/v1/crm/leads/{$this->lead->id}/events", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);

    $this->postJson("/api/v1/crm/leads/{$this->lead->id}/events", ['type' => 'teleported', 'metadata' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type', 'metadata']);
});

test('isolates lead events between tenants', function (): void {
    $otherTenant = Tenant::create(['name' => 'Other Events', 'legal_name' => 'Other Events Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($otherTenant);
    $foreignLead = Lead::create(['tenant_id' => (string) $otherTenant->id, 'email' => 'foreign@lead.it', 'status' => 'new']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->getJson("/api/v1/crm/leads/{$foreignLead->id}/events")->assertNotFound();
    $this->postJson("/api/v1/crm/leads/{$foreignLead->id}/events", ['type' => 'site_visit'])->assertNotFound();
});
