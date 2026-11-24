<?php

declare(strict_types=1);

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Platform\Events\UnreadNotificationsChanged;
use Modules\Platform\Models\IdempotencyKey;
use Modules\Platform\Services\NotificationPreferenceService;

require_once __DIR__.'/../Analytics/BiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = biTenant('Alfa');
    $this->owner = biActAs($this->tenant, 'owner');
});

function platformContactPayload(string $name = 'Rossi Srl'): array
{
    return ['type' => 'customer', 'country' => 'IT', 'legal_type' => 'company', 'company_name' => $name];
}

function platformNotification(string $userId, bool $read = false): string
{
    $id = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $id, 'type' => 'App\\Notifications\\Example', 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => $userId,
        'data' => json_encode(['title' => 'Hello']), 'read_at' => $read ? now() : null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

describe('idempotency', function (): void {
    it('replays the stored response for the same key and payload', function (): void {
        $key = (string) Str::uuid();

        $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/crm/contacts', platformContactPayload());
        $first->assertCreated();

        $second = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/crm/contacts', platformContactPayload());
        $second->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        expect($second->json('data.id'))->toBe($first->json('data.id'))
            ->and(Contact::query()->count())->toBe(1);
    });

    it('refuses the same key with a different payload', function (): void {
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/crm/contacts', platformContactPayload())->assertCreated();

        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/crm/contacts', platformContactPayload('Bianchi Srl'))
            ->assertStatus(422);

        expect(Contact::query()->count())->toBe(1);
    });

    it('answers 409 while the first request with the key is still running', function (): void {
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/crm/contacts', platformContactPayload())->assertCreated();
        IdempotencyKey::query()->update(['response_status' => null, 'response_body' => null]);

        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/crm/contacts', platformContactPayload())->assertStatus(409);

        expect(Contact::query()->count())->toBe(1);
    });

    it('ignores requests without the header', function (): void {
        $this->postJson('/api/v1/crm/contacts', platformContactPayload())->assertCreated();
        expect(IdempotencyKey::query()->count())->toBe(0);
    });

    it('runs again once the key has expired', function (): void {
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/crm/contacts', platformContactPayload())->assertCreated();
        IdempotencyKey::query()->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/crm/contacts', platformContactPayload())->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed');
        expect(Contact::query()->count())->toBe(2);

        $this->artisan('platform:prune-idempotency-keys')->assertSuccessful();
    });
});

describe('optimistic locking', function (): void {
    it('sends an ETag and refuses a stale If-Match with 412', function (): void {
        $contact = biCustomer($this->tenant, 'Rossi Srl');

        $show = $this->getJson("/api/v1/crm/contacts/{$contact->id}")->assertOk();
        $etag = $show->headers->get('ETag');
        expect($etag)->not->toBeNull();

        $this->withHeader('If-None-Match', $etag)->getJson("/api/v1/crm/contacts/{$contact->id}")->assertStatus(304);

        Carbon::setTestNow(now()->addMinute());
        Contact::query()->whereKey($contact->id)->first()->update(['company_name' => 'Changed Srl']);
        Carbon::setTestNow();

        $this->withHeader('If-Match', $etag)->putJson("/api/v1/crm/contacts/{$contact->id}", ['company_name' => 'Mine Srl'])
            ->assertStatus(412);
        expect($contact->refresh()->company_name)->toBe('Changed Srl');

        $fresh = $this->getJson("/api/v1/crm/contacts/{$contact->id}")->headers->get('ETag');
        $this->withHeader('If-Match', $fresh)->putJson("/api/v1/crm/contacts/{$contact->id}", ['company_name' => 'Mine Srl'])
            ->assertOk()->assertHeader('ETag');
        expect($contact->refresh()->company_name)->toBe('Mine Srl');
    });

    it('does not block updates without If-Match', function (): void {
        $contact = biCustomer($this->tenant, 'Rossi Srl');

        $this->putJson("/api/v1/crm/contacts/{$contact->id}", ['company_name' => 'Free Srl'])->assertOk();
    });
});

describe('recycle bin', function (): void {
    it('lists, restores and destroys deleted records', function (): void {
        $gone = biCustomer($this->tenant, 'Deleted Srl');
        $gone->delete();
        $other = biCustomer($this->tenant, 'Alive Srl');

        $this->getJson('/api/v1/platform/recycle-bin/types')->assertOk()
            ->assertJsonFragment(['type' => 'contacts', 'count' => 1, 'purgeable' => true]);

        $list = $this->getJson('/api/v1/platform/recycle-bin?type=contacts')->assertOk();
        expect($list->json('data'))->toHaveCount(1)->and($list->json('data.0.label'))->toBe('Deleted Srl');

        $this->postJson("/api/v1/platform/recycle-bin/contacts/{$gone->id}/restore")->assertOk();
        expect(Contact::query()->whereKey($gone->id)->exists())->toBeTrue();

        $gone->delete();
        $this->deleteJson("/api/v1/platform/recycle-bin/contacts/{$gone->id}")->assertNoContent();
        expect(Contact::withTrashed()->whereKey($gone->id)->exists())->toBeFalse()
            ->and(Contact::query()->whereKey($other->id)->exists())->toBeTrue();
    });

    it('rejects types outside the allowlist, live records, other companies and non-managers', function (): void {
        $this->getJson('/api/v1/platform/recycle-bin?type=users')->assertStatus(422);
        $this->postJson('/api/v1/platform/recycle-bin/users/'.Str::uuid().'/restore')->assertNotFound();

        $alive = biCustomer($this->tenant, 'Alive Srl');
        $this->deleteJson("/api/v1/platform/recycle-bin/contacts/{$alive->id}")->assertNotFound();

        $foreignTenant = biTenant('Beta');
        $foreign = biCustomer($foreignTenant, 'Foreign Srl');
        $foreign->delete();
        $this->postJson("/api/v1/platform/recycle-bin/contacts/{$foreign->id}/restore")->assertNotFound();

        biActAs($this->tenant, 'member');
        $this->getJson('/api/v1/platform/recycle-bin/types')->assertForbidden();
    });
});

describe('notification centre', function (): void {
    it('lists the notifications of the user and marks them read', function (): void {
        $mine = platformNotification($this->owner->id);
        platformNotification($this->owner->id, read: true);
        $foreign = platformNotification((string) biUser($this->tenant)->id);

        $list = $this->getJson('/api/v1/platform/notifications')->assertOk();
        expect($list->json('data'))->toHaveCount(2)->and($list->json('unread_count'))->toBe(1);

        $this->getJson('/api/v1/platform/notifications?unread=1')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/platform/notifications/{$foreign}/read")->assertNotFound();
        $read = $this->postJson("/api/v1/platform/notifications/{$mine}/read")->assertOk();
        expect($read->json('data.read_at'))->not->toBeNull();

        platformNotification($this->owner->id);
        $this->postJson('/api/v1/platform/notifications/read-all')->assertOk()->assertJsonPath('data.marked', 1);
        $this->deleteJson("/api/v1/platform/notifications/{$mine}")->assertNoContent();
    });

    it('stores per-channel preferences and rejects unknown channels', function (): void {
        $matrix = $this->getJson('/api/v1/platform/notifications/preferences')->assertOk();
        expect(collect($matrix->json('data.preferences'))->every(fn ($p) => $p['enabled']))->toBeTrue();

        $this->putJson('/api/v1/platform/notifications/preferences', ['preferences' => [['channel' => 'mail', 'category' => 'billing', 'enabled' => false]]])
            ->assertOk();

        $after = collect($this->getJson('/api/v1/platform/notifications/preferences')->json('data.preferences'));
        expect($after->firstWhere(fn ($p) => $p['channel'] === 'mail' && $p['category'] === 'billing')['enabled'])->toBeFalse()
            ->and($after->firstWhere(fn ($p) => $p['channel'] === 'database' && $p['category'] === 'billing')['enabled'])->toBeTrue();

        expect(app(NotificationPreferenceService::class)->channelsFor($this->owner, 'billing', ['database', 'mail']))->toBe(['database']);

        $this->putJson('/api/v1/platform/notifications/preferences', ['preferences' => [['channel' => 'sms', 'category' => 'billing', 'enabled' => true]]])
            ->assertStatus(422);
    });

    it('broadcasts the unread counter to the private channel of the user when notifications change', function (): void {
        Event::fake([UnreadNotificationsChanged::class]);
        platformNotification($this->owner->id);
        platformNotification($this->owner->id);

        $this->postJson('/api/v1/platform/notifications/read-all')->assertOk();

        Event::assertDispatched(UnreadNotificationsChanged::class, function (UnreadNotificationsChanged $event): bool {
            return $event->userId === (string) $this->owner->id
                && $event->unreadCount === 0
                && $event->broadcastOn()->name === "private-users.{$this->owner->id}.notifications"
                && $event->broadcastWith() === ['unread_count' => 0, 'notification' => null];
        });
    });

    it('exposes the realtime connection only when Reverb is configured', function (): void {
        $this->getJson('/api/v1/platform/notifications/realtime')->assertOk()
            ->assertJsonPath('data.enabled', false)->assertJsonPath('data.key', null)
            ->assertJsonPath('data.channel', "users.{$this->owner->id}.notifications");

        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.options.host' => 'ws.example.test', 'broadcasting.connections.reverb.options.port' => 443]);
        $this->getJson('/api/v1/platform/notifications/realtime')->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.host', 'ws.example.test')->assertJsonPath('data.port', 443)
            ->assertJsonMissingPath('data.secret');
    });

    it('stores the delivery frequency per channel and category and keeps it when only enabled changes', function (): void {
        $this->putJson('/api/v1/platform/notifications/preferences', ['preferences' => [['channel' => 'mail', 'category' => 'crm', 'frequency' => 'digest']]])->assertOk();
        $this->putJson('/api/v1/platform/notifications/preferences', ['preferences' => [['channel' => 'mail', 'category' => 'crm', 'enabled' => true]]])->assertOk();

        $row = collect($this->getJson('/api/v1/platform/notifications/preferences')->json('data.preferences'))
            ->first(fn ($p) => $p['channel'] === 'mail' && $p['category'] === 'crm');
        expect($row['frequency'])->toBe('digest')->and($row['enabled'])->toBeTrue();

        $delivery = app(NotificationPreferenceService::class)->deliveryFor($this->owner, 'crm', ['database', 'mail']);
        expect($delivery['immediate'])->toBe(['database'])->and($delivery['digest'])->toBe(['mail']);

        $this->putJson('/api/v1/platform/notifications/preferences', ['preferences' => [['channel' => 'mail', 'category' => 'crm']]])->assertStatus(422);
        $this->putJson('/api/v1/platform/notifications/preferences', ['preferences' => [['channel' => 'mail', 'category' => 'crm', 'frequency' => 'weekly']]])->assertStatus(422);
    });

    it('silences everything but the in-app channel during quiet hours that cross midnight and during a snooze', function (): void {
        $this->putJson('/api/v1/platform/notifications/settings', [
            'quiet_hours_enabled' => true, 'quiet_start' => '22:00', 'quiet_end' => '07:00', 'timezone' => 'Europe/Rome',
        ])->assertOk()->assertJsonPath('data.quiet_start', '22:00');

        $service = app(NotificationPreferenceService::class);
        $night = Carbon::parse('2027-01-10 23:30:00', 'Europe/Rome');
        $day = Carbon::parse('2027-01-10 12:00:00', 'Europe/Rome');

        expect($service->isSilenced($this->owner, $night))->toBeTrue()
            ->and($service->isSilenced($this->owner, $day))->toBeFalse()
            ->and($service->deliveryFor($this->owner, 'billing', ['database', 'mail'], $night)['immediate'])->toBe(['database'])
            ->and($service->deliveryFor($this->owner, 'billing', ['database', 'mail'], $day)['immediate'])->toBe(['database', 'mail']);

        $this->putJson('/api/v1/platform/notifications/settings', ['quiet_hours_enabled' => false, 'muted_until' => now()->addHour()->toIso8601String()])->assertOk();
        expect($service->isSilenced($this->owner))->toBeTrue();

        $this->putJson('/api/v1/platform/notifications/settings', ['muted_until' => null, 'quiet_start' => '25:00'])->assertStatus(422);
        $this->getJson('/api/v1/platform/notifications/settings')->assertOk()->assertJsonPath('data.quiet_hours_enabled', false);
    });
});
