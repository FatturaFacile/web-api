<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Communication\Jobs\ProcessDueCampaignsJob;
use Modules\Communication\Models\CampaignRecipient;
use Modules\Communication\Models\CommunicationCampaign;
use Modules\Communication\Models\CommunicationChannel;
use Modules\Communication\Models\CommunicationConversation;
use Modules\Communication\Models\DeliveryLog;
use Modules\CRM\Models\Consent;
use Modules\CRM\Models\Segment;

require_once __DIR__.'/../Compliance/ComplianceHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = complianceTenant('Comm '.Str::random(4));
    $this->admin = actingAsMember($this->tenant);
    app(TenantContext::class)->setTenant($this->tenant);
    CommunicationChannel::create(['tenant_id' => $this->tenant->id, 'channel' => 'email', 'driver' => 'local', 'is_enabled' => true]);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function inboundPayload(array $overrides = []): array
{
    return ['channel' => 'email', 'address' => 'Client@Example.com', 'name' => 'Client', 'subject' => 'Question', 'body' => 'Hello there', ...$overrides];
}

function marketingContact(Tenant $tenant, ?string $email, ?string $consent): Contact
{
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'email' => $email]);

    if ($consent !== null) {
        Consent::create(['tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'channel' => 'email', 'purpose' => 'marketing', 'status' => $consent, 'source' => 'api', 'captured_at' => now()]);
    }

    return $contact;
}

function campaignSegment(Tenant $tenant): Segment
{
    return Segment::create(['tenant_id' => $tenant->id, 'name' => 'Everyone', 'entity' => 'contacts', 'filters' => [], 'is_active' => true]);
}

test('inbound messages of one counterparty are aggregated into a single conversation per channel', function (): void {
    $contact = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'client@example.com']);

    $this->postJson('/api/v1/communication/conversations/inbound', inboundPayload())->assertCreated();
    $this->postJson('/api/v1/communication/conversations/inbound', inboundPayload(['body' => 'Second']))->assertCreated();
    $this->postJson('/api/v1/communication/conversations/inbound', inboundPayload(['channel' => 'sms', 'address' => '+39123']))->assertCreated();

    $list = $this->getJson('/api/v1/communication/conversations')->assertOk()->json('data');
    expect($list)->toHaveCount(2);

    $email = collect($list)->firstWhere('channel', 'email');
    expect($email['unread_count'])->toBe(2)->and($email['contact_id'])->toBe((string) $contact->id);

    $this->getJson('/api/v1/communication/conversations/'.$email['id'])->assertOk()->assertJsonCount(2, 'data.messages');
    $this->postJson('/api/v1/communication/conversations/'.$email['id'].'/read')->assertOk()->assertJsonPath('data.unread_count', 0);
    $this->getJson('/api/v1/communication/conversations?channel=sms')->assertJsonCount(1, 'data');
});

test('a reply leaves through the channel driver, is logged and a later inbound message reopens the thread', function (): void {
    $id = $this->postJson('/api/v1/communication/conversations/inbound', inboundPayload())->json('data.conversation_id');

    $this->postJson("/api/v1/communication/conversations/{$id}/reply", ['body' => 'We answer'])
        ->assertCreated()
        ->assertJsonPath('data.direction', 'outbound')
        ->assertJsonPath('data.delivery_status', 'simulated');

    expect(DeliveryLog::query()->where('message_type', 'conversation')->count())->toBe(1);
    $this->getJson("/api/v1/communication/conversations/{$id}")->assertJsonPath('data.status', 'pending');

    $this->postJson("/api/v1/communication/conversations/{$id}/status", ['status' => 'resolved'])->assertOk()->assertJsonPath('data.status', 'resolved');
    $this->postJson('/api/v1/communication/conversations/inbound', inboundPayload(['body' => 'Thanks, one more thing']))->assertCreated();
    $this->getJson("/api/v1/communication/conversations/{$id}")->assertJsonPath('data.status', 'open')->assertJsonPath('data.resolved_at', null);
});

test('a closed conversation cannot be answered and a new inbound message opens another one', function (): void {
    $id = $this->postJson('/api/v1/communication/conversations/inbound', inboundPayload())->json('data.conversation_id');
    $this->postJson("/api/v1/communication/conversations/{$id}/status", ['status' => 'closed'])->assertOk();

    $this->postJson("/api/v1/communication/conversations/{$id}/reply", ['body' => 'Late'])->assertUnprocessable()->assertJsonValidationErrors('status');

    $this->postJson('/api/v1/communication/conversations/inbound', inboundPayload())->assertCreated();
    expect(CommunicationConversation::query()->count())->toBe(2);
});

test('assignment accepts members of the company only and can be cleared', function (): void {
    $id = $this->postJson('/api/v1/communication/conversations/inbound', inboundPayload())->json('data.conversation_id');
    $outsider = User::create(['name' => 'Outsider', 'email' => Str::uuid().'@example.com', 'password' => Str::random(24), 'locale' => 'en']);

    $this->postJson("/api/v1/communication/conversations/{$id}/assign", ['user_id' => (string) $outsider->id])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    $this->postJson("/api/v1/communication/conversations/{$id}/assign", ['user_id' => (string) $this->admin->id])->assertOk()->assertJsonPath('data.assigned_to', (string) $this->admin->id);
    $this->getJson('/api/v1/communication/conversations?assigned_to=none')->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/communication/conversations/{$id}/assign", ['user_id' => null])->assertOk()->assertJsonPath('data.assigned_to', null);
    $this->getJson('/api/v1/communication/conversations?assigned_to=none')->assertJsonCount(1, 'data');
});

test('conversations of another company are not reachable', function (): void {
    $other = Tenant::create(['name' => 'Other '.Str::random(4), 'legal_name' => 'Other Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $foreign = app(TenantContext::class)->runFor($other, fn () => CommunicationConversation::factory()->create(['tenant_id' => $other->id]));

    $this->getJson("/api/v1/communication/conversations/{$foreign->id}")->assertNotFound();
    $this->postJson("/api/v1/communication/conversations/{$foreign->id}/status", ['status' => 'closed'])->assertNotFound();
    expect($this->getJson('/api/v1/communication/conversations')->json('data'))->toBeEmpty();
});

test('read-only roles cannot write to the inbox, the campaigns or the preferences', function (): void {
    actingAsMember($this->tenant, 'accountant_readonly');

    $this->postJson('/api/v1/communication/conversations/inbound', inboundPayload())->assertForbidden();
    $this->postJson('/api/v1/communication/campaigns', ['name' => 'X', 'channel' => 'email', 'body' => 'x'])->assertForbidden();
    $this->getJson('/api/v1/communication/campaigns')->assertOk();
});

test('contact preferences combine the opt-out with the CRM consent', function (): void {
    $contact = marketingContact($this->tenant, 'a@example.com', 'granted');

    $overview = fn (): array => collect($this->getJson("/api/v1/communication/contacts/{$contact->id}/preferences")->assertOk()->json('data'))->keyBy('channel')->all();

    expect($overview()['email']['can_receive_marketing'])->toBeTrue()
        ->and($overview()['email']['marketing_consent'])->toBe('granted')
        ->and($overview()['sms']['can_receive_marketing'])->toBeFalse();

    $this->putJson("/api/v1/communication/contacts/{$contact->id}/preferences", ['channel' => 'email', 'is_opted_out' => true, 'reason' => 'Too many emails'])->assertOk();
    expect($overview()['email']['can_receive_marketing'])->toBeFalse()->and($overview()['email']['is_opted_out'])->toBeTrue();

    $this->putJson("/api/v1/communication/contacts/{$contact->id}/preferences", ['channel' => 'email', 'is_opted_out' => false])->assertOk();
    expect($overview()['email']['can_receive_marketing'])->toBeTrue()->and($overview()['email']['reason'])->toBeNull();
    $this->putJson("/api/v1/communication/contacts/{$contact->id}/preferences", ['channel' => 'fax', 'is_opted_out' => true])->assertUnprocessable();
});

test('a campaign reaches only consenting contacts without opt-out and logs every outcome', function (): void {
    $sent = marketingContact($this->tenant, 'ok@example.com', 'granted');
    $optedOut = marketingContact($this->tenant, 'out@example.com', 'granted');
    $withdrawn = marketingContact($this->tenant, 'gone@example.com', 'withdrawn');
    $unknown = marketingContact($this->tenant, 'none@example.com', null);
    $noAddress = marketingContact($this->tenant, null, 'granted');
    $this->putJson("/api/v1/communication/contacts/{$optedOut->id}/preferences", ['channel' => 'email', 'is_opted_out' => true])->assertOk();
    $segment = campaignSegment($this->tenant);

    $id = $this->postJson('/api/v1/communication/campaigns', ['name' => 'Spring', 'channel' => 'email', 'segment_id' => (string) $segment->id, 'subject' => 'Hi', 'body' => 'Hello {{ name }}'])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

    $this->getJson("/api/v1/communication/campaigns/{$id}/preview")->assertOk()
        ->assertJsonPath('data.total', 5)->assertJsonPath('data.deliverable', 1)
        ->assertJsonPath('data.suppressed.opted_out', 1)->assertJsonPath('data.suppressed.consent_withdrawn', 1)
        ->assertJsonPath('data.suppressed.no_consent', 1)->assertJsonPath('data.suppressed.no_address', 1);

    $this->postJson("/api/v1/communication/campaigns/{$id}/send")->assertOk()
        ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.recipients_count', 5)
        ->assertJsonPath('data.sent_count', 1)->assertJsonPath('data.suppressed_count', 4);

    $log = $this->getJson("/api/v1/communication/campaigns/{$id}/recipients?status=sent")->assertOk()->assertJsonCount(1, 'data')->json('data.0');
    expect($log['contact_id'])->toBe((string) $sent->id)->and($log['address'])->toBe('ok@example.com')->and($log['delivery_log_id'])->not->toBeNull();
    expect(CampaignRecipient::query()->where('campaign_id', $id)->where('status', 'suppressed')->pluck('suppression_reason')->sort()->values()->all())
        ->toBe(['consent_withdrawn', 'no_address', 'no_consent', 'opted_out']);
    expect(DeliveryLog::query()->where('message_type', 'campaign')->count())->toBe(1);

    $this->postJson("/api/v1/communication/campaigns/{$id}/send")->assertUnprocessable();
    $this->putJson("/api/v1/communication/campaigns/{$id}", ['name' => 'Renamed'])->assertUnprocessable();
    expect($withdrawn->id)->not->toBeNull()->and($unknown->id)->not->toBeNull()->and($noAddress->id)->not->toBeNull();
});

test('a scheduled campaign starts only when due and can be cancelled before', function (): void {
    marketingContact($this->tenant, 'ok@example.com', 'granted');
    $segment = campaignSegment($this->tenant);
    $payload = ['name' => 'Later', 'channel' => 'email', 'body' => 'Hello'];

    $noSegment = $this->postJson('/api/v1/communication/campaigns', $payload)->json('data.id');
    $this->postJson("/api/v1/communication/campaigns/{$noSegment}/schedule", ['scheduled_at' => now()->addHour()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('segment_id');

    $id = $this->postJson('/api/v1/communication/campaigns', [...$payload, 'segment_id' => (string) $segment->id])->json('data.id');
    $this->postJson("/api/v1/communication/campaigns/{$id}/schedule", ['scheduled_at' => now()->subHour()->toIso8601String()])->assertUnprocessable();
    $this->postJson("/api/v1/communication/campaigns/{$id}/schedule", ['scheduled_at' => now()->addHour()->toIso8601String()])->assertOk()->assertJsonPath('data.status', 'scheduled');

    ProcessDueCampaignsJob::dispatchSync((string) $this->tenant->id);
    expect(CommunicationCampaign::query()->find($id)->status)->toBe('scheduled');

    $this->travel(2)->hours();
    ProcessDueCampaignsJob::dispatchSync((string) $this->tenant->id);
    $this->getJson("/api/v1/communication/campaigns/{$id}")->assertJsonPath('data.status', 'completed')->assertJsonPath('data.sent_count', 1);

    $cancelled = $this->postJson('/api/v1/communication/campaigns', [...$payload, 'segment_id' => (string) $segment->id])->json('data.id');
    $this->postJson("/api/v1/communication/campaigns/{$cancelled}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/v1/communication/campaigns/{$cancelled}/cancel")->assertUnprocessable();
    $this->deleteJson("/api/v1/communication/campaigns/{$cancelled}")->assertNoContent();
    $this->deleteJson("/api/v1/communication/campaigns/{$id}")->assertUnprocessable();
});

test('a segment of another company is refused and a disabled channel fails the pending recipients', function (): void {
    $other = Tenant::create(['name' => 'Other '.Str::random(4), 'legal_name' => 'Other Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $foreign = app(TenantContext::class)->runFor($other, fn () => campaignSegment($other));

    $this->postJson('/api/v1/communication/campaigns', ['name' => 'X', 'channel' => 'email', 'body' => 'x', 'segment_id' => (string) $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('segment_id');

    marketingContact($this->tenant, 'ok@example.com', 'granted');
    $id = $this->postJson('/api/v1/communication/campaigns', ['name' => 'X', 'channel' => 'email', 'body' => 'x', 'segment_id' => (string) campaignSegment($this->tenant)->id])->json('data.id');
    CommunicationChannel::query()->where('channel', 'email')->update(['is_enabled' => false]);

    $this->postJson("/api/v1/communication/campaigns/{$id}/send")->assertOk()->assertJsonPath('data.failed_count', 1)->assertJsonPath('data.sent_count', 0);
    Sanctum::actingAs($this->admin);
});
