<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\CRM\Models\Campaign;
use Modules\CRM\Models\CampaignMember;
use Modules\CRM\Models\Consent;
use Modules\CRM\Models\Lead;
use Modules\CRM\Notifications\CampaignMessageNotification;

uses(RefreshDatabase::class);

/**
 * Marketing segments, campaign membership, GDPR consent records and consent-aware campaign delivery, plus the access
 * rules of the CRM module for read-only roles.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Marketing Tenant', 'legal_name' => 'Marketing Tenant SpA']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::create(['name' => 'Marketer', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'sales']);
    $this->actingAs($this->user);
    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id);

    $this->campaign = Campaign::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'code' => 'CMP-1', 'name' => 'Autumn Newsletter', 'status' => 'planned']);
});

test('consents are append-only records whose latest entry is the effective state', function (): void {
    $contact = Contact::factory()->create();

    $this->postJson('/api/v1/crm/consents', ['contact_id' => $contact->id, 'channel' => 'email', 'purpose' => 'marketing', 'status' => 'granted', 'source' => 'web_form', 'captured_at' => now()->subDays(10)->toIso8601String(), 'evidence' => 'Form #42'])
        ->assertCreated()->assertJsonPath('status', 'granted');
    $this->postJson('/api/v1/crm/consents', ['contact_id' => $contact->id, 'channel' => 'email', 'purpose' => 'marketing', 'status' => 'withdrawn', 'source' => 'unsubscribe_link'])
        ->assertCreated();
    $this->postJson('/api/v1/crm/consents', ['contact_id' => $contact->id, 'channel' => 'sms', 'purpose' => 'marketing', 'status' => 'granted', 'source' => 'phone'])
        ->assertCreated();
    $this->postJson('/api/v1/crm/consents', ['contact_id' => $contact->id, 'lead_id' => (string) Str::uuid(), 'channel' => 'email', 'purpose' => 'marketing', 'status' => 'granted', 'source' => 'api'])
        ->assertUnprocessable();

    $response = $this->getJson("/api/v1/crm/consents?contact_id={$contact->id}")->assertOk()->assertJsonCount(3, 'history');
    $effective = collect($response->json('effective'))->keyBy(fn (array $state): string => $state['channel'].'|'.$state['purpose']);
    expect($effective['email|marketing']['status'])->toBe('withdrawn')
        ->and($effective['sms|marketing']['status'])->toBe('granted')
        ->and(AuditLedgerEntry::where('action', 'CRM_CONSENT_WITHDRAWN')->count())->toBe(1)
        ->and(TransactionalOutbox::where('event_type', 'crm.consent.recorded')->count())->toBe(3);
});

test('segments validate their filters and evaluate them on current data, consent included', function (): void {
    $optedIn = Contact::factory()->create(['country' => 'CH', 'type' => 'customer']);
    $optedOut = Contact::factory()->create(['country' => 'CH', 'type' => 'customer']);
    Contact::factory()->create(['country' => 'IT', 'type' => 'customer']);
    Consent::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'contact_id' => $optedIn->id, 'channel' => 'email', 'purpose' => 'marketing', 'status' => 'granted', 'source' => 'web_form', 'captured_at' => now()->subDay()]);
    Consent::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'contact_id' => $optedOut->id, 'channel' => 'email', 'purpose' => 'marketing', 'status' => 'granted', 'source' => 'web_form', 'captured_at' => now()->subDays(2)]);
    Consent::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'contact_id' => $optedOut->id, 'channel' => 'email', 'purpose' => 'marketing', 'status' => 'withdrawn', 'source' => 'unsubscribe_link', 'captured_at' => now()->subHour()]);

    $this->postJson('/api/v1/crm/segments', ['name' => 'Bad', 'entity' => 'contacts', 'filters' => [['field' => 'iban', 'operator' => 'eq', 'value' => 'x']]])
        ->assertUnprocessable()->assertJsonValidationErrors('filters');

    $swiss = $this->postJson('/api/v1/crm/segments', ['name' => 'Swiss customers', 'entity' => 'contacts', 'filters' => [
        ['field' => 'country', 'operator' => 'eq', 'value' => 'CH'],
        ['field' => 'type', 'operator' => 'in', 'value' => ['customer', 'client']],
    ]])->assertCreated();
    $this->getJson('/api/v1/crm/segments/'.$swiss->json('id'))->assertOk()->assertJsonPath('member_count', 2);

    $marketable = $this->postJson('/api/v1/crm/segments', ['name' => 'Swiss opted-in', 'entity' => 'contacts', 'filters' => [
        ['field' => 'country', 'operator' => 'eq', 'value' => 'CH'],
        ['field' => 'consent', 'channel' => 'email', 'purpose' => 'marketing'],
    ]])->assertCreated();
    $this->getJson('/api/v1/crm/segments/'.$marketable->json('id').'/members')->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $optedIn->id);

    $this->putJson('/api/v1/crm/segments/'.$swiss->json('id'), ['name' => 'Swiss clients'])->assertOk()->assertJsonPath('name', 'Swiss clients');
    $this->getJson('/api/v1/crm/segments')->assertOk()->assertJsonCount(2, 'data');
    $this->deleteJson('/api/v1/crm/segments/'.$swiss->json('id'))->assertNoContent();
});

test('campaign members are added from contacts, leads and segments without duplicates', function (): void {
    $contact = Contact::factory()->create(['country' => 'FR']);
    Contact::factory()->create(['country' => 'FR']);
    $lead = Lead::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'first_name' => 'Lea', 'email' => 'lea@x.fr', 'status' => 'new']);
    $segment = $this->postJson('/api/v1/crm/segments', ['name' => 'France', 'entity' => 'contacts', 'filters' => [['field' => 'country', 'operator' => 'eq', 'value' => 'FR']]])->assertCreated();

    $this->postJson("/api/v1/crm/campaigns/{$this->campaign->id}/members", ['contact_id' => $contact->id])->assertCreated()->assertJsonPath('added', 1);
    $this->postJson("/api/v1/crm/campaigns/{$this->campaign->id}/members", ['lead_id' => $lead->id])->assertCreated()->assertJsonPath('added', 1);
    $this->postJson("/api/v1/crm/campaigns/{$this->campaign->id}/members", ['segment_id' => $segment->json('id')])
        ->assertCreated()->assertJsonPath('added', 1)->assertJsonPath('already_members', 1);
    $this->postJson("/api/v1/crm/campaigns/{$this->campaign->id}/members", ['contact_id' => $contact->id, 'lead_id' => $lead->id])->assertUnprocessable();

    $members = $this->getJson("/api/v1/crm/campaigns/{$this->campaign->id}/members")->assertOk()->assertJsonPath('meta.total', 3);
    $this->deleteJson("/api/v1/crm/campaigns/{$this->campaign->id}/members/".$members->json('data.0.id'))->assertNoContent();
    $this->getJson("/api/v1/crm/campaigns/{$this->campaign->id}/members")->assertJsonPath('meta.total', 2);
});

test('sending a campaign emails only members with a current opt-in and suppresses the others', function (): void {
    Notification::fake();
    $granted = Contact::factory()->create(['email' => 'yes@example.com']);
    $withdrawn = Contact::factory()->create(['email' => 'stop@example.com']);
    $silent = Contact::factory()->create(['email' => 'never-asked@example.com']);
    $leadGranted = Lead::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'first_name' => 'Lead', 'email' => 'lead-yes@example.com', 'status' => 'new']);

    foreach ([[$granted->id, null, 'granted'], [$withdrawn->id, null, 'granted'], [$withdrawn->id, null, 'withdrawn'], [null, $leadGranted->id, 'granted']] as $index => [$contactId, $leadId, $status]) {
        Consent::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'contact_id' => $contactId, 'lead_id' => $leadId, 'channel' => 'email', 'purpose' => 'marketing', 'status' => $status, 'source' => 'web_form', 'captured_at' => now()->subMinutes(10 - $index)]);
    }
    foreach ([$granted, $withdrawn, $silent] as $contact) {
        $this->postJson("/api/v1/crm/campaigns/{$this->campaign->id}/members", ['contact_id' => $contact->id])->assertCreated();
    }
    $this->postJson("/api/v1/crm/campaigns/{$this->campaign->id}/members", ['lead_id' => $leadGranted->id])->assertCreated();

    $this->postJson("/api/v1/crm/campaigns/{$this->campaign->id}/send", ['subject' => 'News', 'body' => 'Hello'])
        ->assertOk()
        ->assertJsonPath('sent', 2)
        ->assertJsonPath('suppressed', 2)
        ->assertJsonPath('suppression_reasons.opted_out', 1)
        ->assertJsonPath('suppression_reasons.no_consent', 1);

    Notification::assertSentOnDemand(CampaignMessageNotification::class, fn (CampaignMessageNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => in_array($notifiable->routes['mail'], ['yes@example.com', 'lead-yes@example.com'], true));
    Notification::assertSentOnDemandTimes(CampaignMessageNotification::class, 2);

    app(TenantContext::class)->setTenant($this->tenant);
    expect(CampaignMember::where('contact_id', $withdrawn->id)->value('suppression_reason'))->toBe('opted_out')
        ->and(Campaign::find($this->campaign->id)->status)->toBe('active');

    // A second run contacts nobody twice.
    $this->postJson("/api/v1/crm/campaigns/{$this->campaign->id}/send", ['subject' => 'News', 'body' => 'Hello'])->assertOk()->assertJsonPath('sent', 0)->assertJsonPath('skipped', 2);
    Notification::assertSentOnDemandTimes(CampaignMessageNotification::class, 2);
});

test('a lead conversion carries the lead consents over to the contact', function (): void {
    $lead = Lead::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'first_name' => 'Nora', 'company_name' => 'Nora Srl', 'email' => 'nora@nora.it', 'status' => 'qualified']);
    $this->postJson('/api/v1/crm/consents', ['lead_id' => $lead->id, 'channel' => 'email', 'purpose' => 'newsletter', 'status' => 'granted', 'source' => 'web_form'])->assertCreated();

    $contactId = $this->postJson("/api/v1/crm/leads/{$lead->id}/convert", ['create_opportunity' => false])->assertOk()->json('contact_id');

    $this->getJson("/api/v1/crm/consents?contact_id={$contactId}")->assertOk()
        ->assertJsonPath('effective.0.status', 'granted')
        ->assertJsonPath('history.0.source', 'lead_conversion');
});

test('read-only roles can read the CRM but every write is refused', function (string $method, string $uri): void {
    $reader = User::create(['name' => 'Reader', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $reader->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    Sanctum::actingAs($reader);

    $this->getJson('/api/v1/crm/segments')->assertOk();
    $this->getJson('/api/v1/crm/reports/pipeline')->assertOk();

    $this->json($method, str_replace('{campaign}', (string) $this->campaign->id, $uri), [])->assertForbidden();
})->with([
    ['POST', '/api/v1/crm/accounts'],
    ['POST', '/api/v1/crm/lead-scoring-rules'],
    ['POST', '/api/v1/crm/lead-scoring-rules/recalculate'],
    ['POST', '/api/v1/crm/pipeline-stages/defaults'],
    ['POST', '/api/v1/crm/contact-merges'],
    ['POST', '/api/v1/crm/segments'],
    ['POST', '/api/v1/crm/consents'],
    ['POST', '/api/v1/crm/campaigns/{campaign}/members'],
    ['POST', '/api/v1/crm/campaigns/{campaign}/send'],
    ['POST', '/api/v1/crm/activities'],
]);

test('marketing data of another tenant is invisible', function (): void {
    $other = Tenant::create(['name' => 'Other', 'legal_name' => 'Other SpA']);
    $foreignCampaign = app(TenantContext::class)->runWithTenant($other, fn () => Campaign::create(['id' => (string) Str::uuid7(), 'code' => 'X', 'name' => 'Foreign']));
    $foreignContact = app(TenantContext::class)->runWithTenant($other, fn () => Contact::factory()->create());

    $this->getJson("/api/v1/crm/campaigns/{$foreignCampaign->id}/members")->assertNotFound();
    $this->postJson("/api/v1/crm/campaigns/{$this->campaign->id}/members", ['contact_id' => $foreignContact->id])->assertUnprocessable();
    $this->postJson('/api/v1/crm/consents', ['contact_id' => $foreignContact->id, 'channel' => 'email', 'purpose' => 'marketing', 'status' => 'granted', 'source' => 'api'])->assertUnprocessable();
});
