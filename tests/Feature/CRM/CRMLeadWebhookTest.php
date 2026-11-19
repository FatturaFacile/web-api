<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadActivityEvent;
use Modules\CRM\Models\LeadDuplicateCandidate;
use Modules\CRM\Models\LeadRoutingRule;
use Modules\CRM\Models\LeadScoringRule;
use Modules\CRM\Models\LeadSetting;
use Modules\CRM\Models\LeadSource;
use Modules\CRM\Notifications\LeadAssignedNotification;
use Modules\CRM\Notifications\LeadDuplicateDetectedNotification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();

    $this->tenant = Tenant::create(['name' => 'Webhook Tenant', 'legal_name' => 'Webhook Tenant Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->makeUser = function (string $role = 'member', ?Tenant $tenant = null): User {
        $user = User::create(['name' => 'User '.Str::random(4), 'email' => Str::lower(Str::random(12)).'@webhook.test', 'password' => bcrypt('secret')]);
        $user->tenants()->attach(($tenant ?? $this->tenant)->id, ['id' => (string) Str::uuid(), 'role' => $role]);

        return $user;
    };

    $this->admin = ($this->makeUser)('admin');
    $this->rep = ($this->makeUser)('member');

    LeadRoutingRule::create([
        'tenant_id' => (string) $this->tenant->id,
        'name' => 'Everyone to the rep',
        'priority' => 10,
        'strategy' => 'round_robin',
        'assignee_user_ids' => [(string) $this->rep->id],
        'is_default' => true,
        'is_active' => true,
    ]);

    $this->secret = 'whsec_'.Str::random(32);
    $this->apiKey = 'ffl_'.Str::random(40);

    $this->makeSource = fn (Tenant $tenant, array $attributes = []): LeadSource => app(TenantContext::class)->runWithTenant(
        $tenant,
        fn (): LeadSource => LeadSource::create(array_merge([
            'tenant_id' => (string) $tenant->id,
            'code' => 'web-'.Str::random(6),
            'name' => 'Website form',
            'type' => 'web_form',
            'is_active' => true,
            'webhook_secret' => $this->secret,
            'api_key_prefix' => substr($this->apiKey, 0, 12),
            'api_key_hash' => hash('sha256', $this->apiKey),
            'allowed_origins' => ['https://www.example-shop.it'],
        ], $attributes)),
    );

    $this->source = ($this->makeSource)($this->tenant);

    // Signed JSON delivery exactly like a marketing tool would send it.
    $this->deliver = function (string $sourceId, array $payload, array $options = []): TestResponse {
        $body = (string) json_encode($payload);
        $timestamp = (string) ($options['timestamp'] ?? now()->getTimestamp());
        $secret = $options['secret'] ?? $this->secret;
        $path = $options['path'] ?? "/api/v1/webhooks/crm/leads/{$sourceId}";

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Timestamp' => $timestamp,
            'X-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ];

        return $this->call('POST', $path, [], [], [], $this->transformHeadersToServerVars($headers), $body);
    };

    $this->leadCount = fn (): int => Lead::withoutGlobalScopes()->count();
});

test('a valid signed web form creates, scores and routes a lead and notifies the assignee', function (): void {
    $response = ($this->deliver)((string) $this->source->id, [
        'submission_id' => 'form-1',
        'first_name' => 'Giulia',
        'last_name' => 'Ferri',
        'email' => 'giulia@ferri-impianti.it',
        'phone' => '333 765 4321',
        'company' => 'Ferri Impianti Srl',
        'job_title' => 'Owner',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'created')
        ->assertJsonPath('data.assigned_user_id', (string) $this->rep->id)
        ->assertJsonStructure(['data' => ['lead_id', 'status', 'score', 'rating', 'assigned_user_id']]);

    $lead = Lead::findOrFail($response->json('data.lead_id'));

    expect($lead->tenant_id)->toBe((string) $this->tenant->id)
        ->and($lead->lead_source_id)->toBe((string) $this->source->id)
        ->and($lead->external_id)->toBe('form-1')
        ->and($lead->phone_e164)->toBe('+393337654321')
        ->and($lead->company_name_normalized)->toBe('ferri impianti')
        ->and($lead->assigned_user_id)->toBe((string) $this->rep->id)
        ->and($lead->score)->toBe($response->json('data.score'))
        ->and($lead->score_breakdown)->not->toBeEmpty()
        ->and($this->source->fresh()->last_received_at)->not->toBeNull();

    Notification::assertSentTo(
        $this->rep,
        LeadAssignedNotification::class,
        function (LeadAssignedNotification $notification, array $channels) use ($lead): bool {
            $payload = $notification->toArray($this->rep);

            return $channels === ['database', 'mail']
                && $payload['lead_id'] === (string) $lead->id
                && array_keys(array_intersect_key($payload, array_flip(['lead_id', 'title', 'rating', 'score', 'url']))) === ['lead_id', 'title', 'rating', 'score', 'url'];
        }
    );
});

test('rejects tampered, stale and unsigned deliveries without persisting anything', function (): void {
    $before = ($this->leadCount)();
    $payload = ['email' => 'nope@example.com'];

    ($this->deliver)((string) $this->source->id, $payload, ['secret' => 'wrong-secret'])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthorized.']);

    ($this->deliver)((string) $this->source->id, $payload, ['timestamp' => now()->subMinutes(6)->getTimestamp()])
        ->assertUnauthorized();

    $this->postJson("/api/v1/webhooks/crm/leads/{$this->source->id}", $payload)->assertUnauthorized();

    expect(($this->leadCount)())->toBe($before);
    Notification::assertNothingSent();
});

test('returns 404 for unknown sources and 401 for inactive ones', function (): void {
    ($this->deliver)((string) Str::uuid7(), ['email' => 'x@example.com'])->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    ($this->deliver)('not-a-uuid', ['email' => 'x@example.com'])->assertNotFound();

    $this->source->update(['is_active' => false]);

    ($this->deliver)((string) $this->source->id, ['email' => 'x@example.com'])->assertUnauthorized();
});

test('replaying the same external id is idempotent', function (): void {
    $payload = ['submission_id' => 'form-replay', 'email' => 'replay@example.com', 'company' => 'Replay Srl'];

    $first = ($this->deliver)((string) $this->source->id, $payload)->assertCreated();
    $count = ($this->leadCount)();

    ($this->deliver)((string) $this->source->id, $payload)
        ->assertOk()
        ->assertJsonPath('data.lead_id', $first->json('data.lead_id'))
        ->assertJsonPath('data.status', 'duplicate_ignored')
        ->assertJsonPath('data.deduplicated', true);

    expect(($this->leadCount)())->toBe($count);
});

test('auto_merge enriches the existing lead on an exact duplicate and notifies its owner', function (): void {
    LeadSetting::create(['tenant_id' => (string) $this->tenant->id, 'dedup_mode' => 'auto_merge']);
    $owner = ($this->makeUser)();
    $existing = Lead::create([
        'tenant_id' => (string) $this->tenant->id,
        'first_name' => 'Marta',
        'email' => 'marta@sala.ch',
        'status' => 'new',
        'assigned_user_id' => (string) $owner->id,
    ]);
    $count = ($this->leadCount)();

    ($this->deliver)((string) $this->source->id, [
        'submission_id' => 'merge-1',
        'email' => 'MARTA@sala.ch',
        'last_name' => 'Sala',
        'company' => 'Sala Consulting Sagl',
        'phone' => '+41 91 123 45 67',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'merged')
        ->assertJsonPath('data.lead_id', (string) $existing->id)
        ->assertJsonPath('data.merged_into', (string) $existing->id)
        ->assertJsonPath('data.deduplicated', true);

    $fresh = $existing->fresh();

    expect(($this->leadCount)())->toBe($count)
        ->and($fresh->first_name)->toBe('Marta')
        ->and($fresh->last_name)->toBe('Sala')
        ->and($fresh->company_name)->toBe('Sala Consulting Sagl')
        ->and($fresh->phone_e164)->toBe('+41911234567')
        ->and(LeadActivityEvent::where('lead_id', $existing->id)->where('type', 'form_submitted')->count())->toBe(1);

    Notification::assertSentTo($owner, LeadDuplicateDetectedNotification::class, fn (LeadDuplicateDetectedNotification $notification): bool => $notification->merged && $notification->matchType === 'email');

    // Replaying the merged submission does not merge it twice.
    ($this->deliver)((string) $this->source->id, ['submission_id' => 'merge-1', 'email' => 'marta@sala.ch'])
        ->assertOk()
        ->assertJsonPath('data.status', 'duplicate_ignored')
        ->assertJsonPath('data.lead_id', (string) $existing->id);

    expect(LeadActivityEvent::where('lead_id', $existing->id)->where('type', 'form_submitted')->count())->toBe(1);
});

test('notify mode creates the lead, records a pending candidate and notifies the existing owner', function (): void {
    $owner = ($this->makeUser)();
    $existing = Lead::create([
        'tenant_id' => (string) $this->tenant->id,
        'first_name' => 'Ugo',
        'vat_number' => 'IT01234567890',
        'status' => 'new',
        'assigned_user_id' => (string) $owner->id,
    ]);

    $response = ($this->deliver)((string) $this->source->id, ['email' => 'ugo@new.it', 'partita_iva' => '01234567890'])->assertCreated();
    $newLeadId = $response->json('data.lead_id');

    $candidate = LeadDuplicateCandidate::where('lead_id', $newLeadId)->firstOrFail();

    expect($newLeadId)->not->toBe((string) $existing->id)
        ->and($candidate->candidate_lead_id)->toBe((string) $existing->id)
        ->and($candidate->match_type)->toBe('vat')
        ->and($candidate->status)->toBe('pending');

    Notification::assertSentTo($owner, LeadDuplicateDetectedNotification::class, fn (LeadDuplicateDetectedNotification $notification): bool => ! $notification->merged && $notification->duplicateId === (string) $candidate->id);
});

test('fuzzy company matches are never auto merged', function (): void {
    LeadSetting::create(['tenant_id' => (string) $this->tenant->id, 'dedup_mode' => 'auto_merge']);
    $existing = Lead::create(['tenant_id' => (string) $this->tenant->id, 'company_name' => 'Costruzioni Bernasconi SA', 'status' => 'new']);

    $response = ($this->deliver)((string) $this->source->id, ['company' => 'Costruzione Bernasconi Sagl', 'email' => 'info@bernasconi.ch'])->assertCreated();

    $candidate = LeadDuplicateCandidate::where('lead_id', $response->json('data.lead_id'))->firstOrFail();

    expect($candidate->candidate_lead_id)->toBe((string) $existing->id)
        ->and($candidate->match_type)->toBe('company_fuzzy')
        ->and($candidate->confidence)->toBeGreaterThan(0.8)
        ->and($existing->fresh()->email)->toBeNull();
});

test('rejects payloads without email, phone or company with field errors', function (): void {
    $before = ($this->leadCount)();

    ($this->deliver)((string) $this->source->id, ['first_name' => 'Anonymous', 'message' => 'hi'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'phone', 'company_name']);

    ($this->deliver)((string) $this->source->id, ['email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    expect(($this->leadCount)())->toBe($before);
});

test('throttles floods per source and IP with Retry-After', function (): void {
    config(['crm.lead_webhook.rate_limit_per_minute' => 2]);

    ($this->deliver)((string) $this->source->id, ['email' => 'flood1@example.com'])->assertCreated();
    ($this->deliver)((string) $this->source->id, ['email' => 'flood2@example.com'])->assertCreated();

    ($this->deliver)((string) $this->source->id, ['email' => 'flood3@example.com'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    // Another source has its own budget.
    $other = ($this->makeSource)($this->tenant);
    ($this->deliver)((string) $other->id, ['email' => 'flood4@example.com'])->assertCreated();
});

test('accepts browser forms with API key, allowed origin and empty honeypot', function (): void {
    $headers = ['X-Api-Key' => $this->apiKey, 'Origin' => 'https://www.example-shop.it', 'Accept' => 'application/json'];

    $this->withHeaders($headers)
        ->post("/api/v1/webhooks/crm/leads/{$this->source->id}", ['email' => 'browser@example.com', '_hp' => ''])
        ->assertCreated();

    $this->withHeaders($headers)
        ->post("/api/v1/webhooks/crm/leads/{$this->source->id}", ['email' => 'bot@example.com', '_hp' => 'filled by a bot'])
        ->assertUnauthorized();

    $this->withHeaders(array_merge($headers, ['Origin' => 'https://evil.example.com']))
        ->post("/api/v1/webhooks/crm/leads/{$this->source->id}", ['email' => 'evil@example.com'])
        ->assertUnauthorized();

    expect(Lead::withoutGlobalScopes()->where('email', 'bot@example.com')->exists())->toBeFalse();

    // Plain HTML forms cannot set headers: the key may travel as the _api_key field and is never stored.
    $plain = $this->withHeaders(['Origin' => 'https://www.example-shop.it', 'Accept' => 'application/json'])
        ->post("/api/v1/webhooks/crm/leads/{$this->source->id}", ['email' => 'plain@example.com', '_api_key' => $this->apiKey, '_hp' => ''])
        ->assertCreated();

    expect(Lead::findOrFail($plain->json('data.lead_id'))->custom_fields)->not->toHaveKey('_api_key');
});

test('leaves the lead unassigned and notifies admins when no routing rule matches', function (): void {
    LeadRoutingRule::query()->delete();

    $response = ($this->deliver)((string) $this->source->id, ['email' => 'orphan@example.com'])
        ->assertCreated()
        ->assertJsonPath('data.assigned_user_id', null);

    expect(Lead::findOrFail($response->json('data.lead_id'))->assigned_user_id)->toBeNull();

    Notification::assertSentTo($this->admin, LeadAssignedNotification::class, fn (LeadAssignedNotification $notification): bool => $notification->unassigned);
    Notification::assertNotSentTo($this->rep, LeadAssignedNotification::class);
});

test('uses the tenant of the source and ignores any client supplied tenant', function (): void {
    $otherTenant = Tenant::create(['name' => 'Other Tenant', 'legal_name' => 'Other Tenant SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $otherSource = ($this->makeSource)($otherTenant);

    $response = ($this->deliver)((string) $otherSource->id, [
        'email' => 'crossing@example.com',
        'tenant_id' => (string) $this->tenant->id,
    ])->assertCreated();

    $lead = Lead::withoutGlobalScopes()->findOrFail($response->json('data.lead_id'));

    expect($lead->tenant_id)->toBe((string) $otherTenant->id)
        ->and($lead->country)->toBe('IT');
});

test('records behavioural events from marketing tools and re-scores the lead', function (): void {
    LeadScoringRule::create([
        'tenant_id' => (string) $this->tenant->id,
        'code' => 'catalog',
        'name' => 'Catalog download',
        'category' => 'behavioral',
        'event_type' => 'catalog_downloaded',
        'operator' => 'exists',
        'points' => 25,
        'is_active' => true,
    ]);
    $lead = Lead::create(['tenant_id' => (string) $this->tenant->id, 'email' => 'reader@example.com', 'status' => 'new']);

    $response = ($this->deliver)((string) $this->source->id, [
        'email' => 'Reader@Example.com',
        'type' => 'catalog_downloaded',
        'occurred_at' => now()->toIso8601String(),
        'metadata' => ['catalog' => '2027'],
        'external_id' => 'mc-1',
    ], ['path' => "/api/v1/webhooks/crm/leads/{$this->source->id}/events"]);

    $response->assertCreated()
        ->assertJsonPath('data.lead_id', (string) $lead->id)
        ->assertJsonPath('data.score', 25);

    ($this->deliver)((string) $this->source->id, ['email' => 'reader@example.com', 'type' => 'catalog_downloaded', 'external_id' => 'mc-1'], ['path' => "/api/v1/webhooks/crm/leads/{$this->source->id}/events"])
        ->assertOk();

    ($this->deliver)((string) $this->source->id, ['email' => 'unknown@example.com', 'type' => 'site_visit'], ['path' => "/api/v1/webhooks/crm/leads/{$this->source->id}/events"])
        ->assertNotFound();

    expect(LeadActivityEvent::where('lead_id', $lead->id)->count())->toBe(1)
        ->and($lead->fresh()->score)->toBe(25);
});

test('answers the Meta subscription handshake only with the source API key', function (): void {
    $meta = ($this->makeSource)($this->tenant, ['type' => 'meta_lead_ads']);
    $query = 'hub.mode=subscribe&hub.challenge=challenge-123&hub.verify_token=';

    $this->get("/api/v1/webhooks/crm/leads/{$meta->id}?{$query}{$this->apiKey}")
        ->assertOk()
        ->assertSeeText('challenge-123');

    $this->getJson("/api/v1/webhooks/crm/leads/{$meta->id}?{$query}wrong")->assertUnauthorized();
    $this->getJson("/api/v1/webhooks/crm/leads/{$this->source->id}?{$query}{$this->apiKey}")->assertUnauthorized();
});

test('lead source credentials are shown once and never exposed afterwards', function (): void {
    Sanctum::actingAs($this->admin);

    $created = $this->postJson('/api/v1/crm/lead-sources', [
        'code' => 'landing',
        'name' => 'Landing page',
        'type' => 'web_form',
        'allowed_origins' => ['https://landing.example.com'],
    ])->assertCreated();

    $secret = $created->json('data.webhook_secret');
    $apiKey = $created->json('data.api_key');
    $sourceId = $created->json('data.id');

    expect($secret)->toBeString()->not->toBeEmpty()
        ->and($apiKey)->toStartWith('ffl_')
        ->and($created->json('data.webhook_url'))->toEndWith("/api/v1/webhooks/crm/leads/{$sourceId}")
        ->and($created->json('data.api_key_prefix'))->toBe(substr($apiKey, 0, 12));

    $list = $this->getJson('/api/v1/crm/lead-sources')->assertOk();
    $show = $this->getJson("/api/v1/crm/lead-sources/{$sourceId}")->assertOk();

    foreach ([$list->getContent(), $show->getContent()] as $content) {
        expect($content)->not->toContain($secret)
            ->not->toContain($apiKey)
            ->not->toContain('api_key_hash')
            ->not->toContain('"webhook_secret"');
    }

    // The stored secret is encrypted at rest and the API key only hashed.
    $raw = DB::table('crm_lead_sources')->where('id', $sourceId)->first();
    expect($raw->webhook_secret)->not->toBe($secret)
        ->and($raw->api_key_hash)->toBe(hash('sha256', $apiKey));

    $rotated = $this->postJson("/api/v1/crm/lead-sources/{$sourceId}/rotate-secret", ['rotate' => 'webhook_secret'])->assertOk();
    expect($rotated->json('data.webhook_secret'))->not->toBe($secret)
        ->and($rotated->json('data'))->not->toHaveKey('api_key');

    // The rotated secret signs deliveries; the old one no longer does.
    ($this->deliver)($sourceId, ['email' => 'rotated@example.com'], ['secret' => $secret])->assertUnauthorized();
    ($this->deliver)($sourceId, ['email' => 'rotated@example.com'], ['secret' => $rotated->json('data.webhook_secret')])->assertCreated();
});
