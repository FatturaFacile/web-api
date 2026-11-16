<?php

declare(strict_types=1);

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Platform\Models\NotificationAlertRule;
use Modules\Platform\Models\NotificationDelivery;
use Modules\Platform\Models\NotificationDigestItem;
use Modules\Platform\Models\NotificationSetting;
use Modules\Platform\Services\AlertRuleEvaluator;
use Modules\Platform\Services\NotificationDigestService;
use Modules\Platform\Services\NotificationDispatcher;
use Modules\Platform\Services\NotificationPreferenceService;

require_once __DIR__.'/../Analytics/BiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = biTenant('Alfa');
    $this->owner = biActAs($this->tenant, 'owner');
    config(['platform.telegram.bot_token' => 'bot-token', 'platform.telegram.bot_username' => 'ff_bot', 'platform.telegram.webhook_secret' => 'hook-secret']);
});

function linkTelegramChat(object $user, string $chat = '4242'): void
{
    NotificationSetting::query()->updateOrCreate(['user_id' => $user->id], ['telegram_chat_id' => $chat]);
}

describe('devices and register', function (): void {
    it('registers a device, moves a known token to the new user and lists only own devices', function (): void {
        $this->postJson('/api/v1/platform/notifications/devices', ['platform' => 'android', 'token' => 'tok-1', 'name' => 'Pixel'])->assertCreated();
        $this->postJson('/api/v1/platform/notifications/devices', ['platform' => 'bogus', 'token' => 'tok-2'])->assertStatus(422);

        $list = $this->getJson('/api/v1/platform/notifications/devices')->assertOk();
        expect($list->json('data'))->toHaveCount(1);

        $this->deleteJson('/api/v1/platform/notifications/devices/'.$list->json('data.0.id'))->assertNoContent();
        expect($this->getJson('/api/v1/platform/notifications/devices')->json('data'))->toBeEmpty();
    });
});

describe('dispatcher and delivery register', function (): void {
    it('sends on the linked chat, logs it and marks it opened when the notification is read', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        linkTelegramChat($this->owner);

        app(NotificationDispatcher::class)->send($this->owner, 'billing', 'Invoice paid', 'INV-1 was paid', '/invoices/1');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'botbot-token/sendMessage') && $request['chat_id'] === '4242' && str_contains($request['text'], 'Invoice paid'));

        $rows = NotificationDelivery::query()->where('user_id', $this->owner->id)->get()->keyBy('channel');
        expect($rows->keys()->sort()->values()->all())->toBe(['database', 'mail', 'push', 'telegram', 'whatsapp'])
            ->and($rows['telegram']->status)->toBe('sent');

        // push and whatsapp are enabled by default but have nothing to deliver to: they fail and say why.
        $failed = NotificationDelivery::query()->where('status', 'failed')->pluck('channel')->sort()->values()->all();
        expect($failed)->toBe(['push', 'whatsapp']);
    });

    it('reports a failed channel once and opens every delivery of the notification when it is read', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);
        linkTelegramChat($this->owner);

        app(NotificationDispatcher::class)->send($this->owner, 'crm', 'Lead assigned', null, '/crm/leads');

        $telegram = NotificationDelivery::query()->where('channel', 'telegram')->get();
        expect($telegram)->toHaveCount(1)->and($telegram->first()->status)->toBe('failed');

        $notificationId = $this->owner->notifications()->firstOrFail()->id;
        $this->postJson("/api/v1/platform/notifications/{$notificationId}/read")->assertOk();

        $opened = NotificationDelivery::query()->where('notification_id', $notificationId)->where('status', 'opened')->count();
        expect($opened)->toBe(NotificationDelivery::query()->where('notification_id', $notificationId)->count());

        $this->getJson('/api/v1/platform/notifications/deliveries?status=opened')->assertOk()->assertJsonPath('meta.total', $opened);
    });

    it('queues digest channels and channels held by quiet hours, and sends them as one digest', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        linkTelegramChat($this->owner);
        app(NotificationPreferenceService::class)->update($this->owner, [['channel' => 'telegram', 'category' => 'hr', 'frequency' => 'digest']]);

        app(NotificationDispatcher::class)->send($this->owner, 'hr', 'Leave request A');
        app(NotificationDispatcher::class)->send($this->owner, 'hr', 'Leave request B');

        Http::assertNothingSent();
        expect(NotificationDigestItem::query()->where('channel', 'telegram')->count())->toBe(2)
            ->and(NotificationDelivery::query()->where('channel', 'telegram')->where('status', 'queued')->count())->toBe(2)
            // the in-app notification is never held back
            ->and($this->owner->notifications()->count())->toBe(2);

        // quiet hours hold the mail of another category too
        app(NotificationPreferenceService::class)->updateSettings($this->owner, ['muted_until' => now()->addHour()]);
        app(NotificationDispatcher::class)->send($this->owner, 'billing', 'Overdue');
        expect(NotificationDigestItem::query()->where('channel', 'mail')->exists())->toBeTrue();

        // while silenced the digest waits
        expect(app(NotificationDigestService::class)->sendDue())->toBe(0);

        app(NotificationPreferenceService::class)->updateSettings($this->owner, ['muted_until' => null]);
        $sent = app(NotificationDigestService::class)->sendDue();

        expect($sent)->toBeGreaterThanOrEqual(2)->and(NotificationDigestItem::query()->count())->toBe(0);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Leave request A') && str_contains($request['text'], 'Leave request B'));
        expect(NotificationDelivery::query()->where('status', 'queued')->count())->toBe(0);
    });
});

describe('telegram link', function (): void {
    it('links the chat of the sender when the bot receives /start with the token, only with the right secret', function (): void {
        $url = $this->postJson('/api/v1/platform/notifications/channels/telegram/link')->assertOk()->json('data.url');
        expect($url)->toStartWith('https://t.me/ff_bot?start=');
        $token = substr($url, strlen('https://t.me/ff_bot?start='));

        $payload = ['message' => ['text' => "/start {$token}", 'chat' => ['id' => 777]]];
        $this->postJson('/api/v1/platform/telegram/webhook/wrong', $payload)->assertNotFound();
        $this->postJson('/api/v1/platform/telegram/webhook/hook-secret', $payload)->assertOk();

        $this->getJson('/api/v1/platform/notifications/settings')->assertOk()->assertJsonPath('data.telegram_linked', true);

        // the token is single use
        $this->postJson('/api/v1/platform/telegram/webhook/hook-secret', ['message' => ['text' => "/start {$token}", 'chat' => ['id' => 888]]])->assertOk();
        expect(NotificationSetting::query()->where('user_id', $this->owner->id)->value('telegram_chat_id'))->toBe('777');

        $this->deleteJson('/api/v1/platform/notifications/channels/telegram')->assertNoContent();
        $this->getJson('/api/v1/platform/notifications/settings')->assertJsonPath('data.telegram_linked', false);
    });

    it('stores a WhatsApp number in E.164 format only', function (): void {
        $this->putJson('/api/v1/platform/notifications/settings', ['whatsapp_number' => '0791234567'])->assertStatus(422);
        $this->putJson('/api/v1/platform/notifications/settings', ['whatsapp_number' => '+41791234567'])->assertOk()->assertJsonPath('data.whatsapp_number', '+41791234567');
    });

    it('sends WhatsApp text through the Cloud API', function (): void {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]])]);
        config(['platform.whatsapp.token' => 'wa', 'platform.whatsapp.phone_number_id' => '555']);
        NotificationSetting::query()->updateOrCreate(['user_id' => $this->owner->id], ['whatsapp_number' => '+41791234567']);

        app(NotificationDispatcher::class)->send($this->owner, 'system', 'Stock low', 'Item X', '/inventory');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/555/messages') && $request['to'] === '41791234567' && str_contains($request['text']['body'], 'Stock low'));
        expect(NotificationDelivery::query()->where('channel', 'whatsapp')->value('status'))->toBe('sent');
    });
});

describe('alert rules', function (): void {
    it('lets administrators manage rules and rejects unknown metrics', function (): void {
        $created = $this->postJson('/api/v1/platform/notification-alert-rules', ['name' => 'Unpaid over 10k', 'metric' => 'customer_overdue_total', 'threshold' => 10000, 'currency' => 'CHF'])->assertCreated();
        $this->postJson('/api/v1/platform/notification-alert-rules', ['name' => 'x', 'metric' => 'nope', 'threshold' => 1])->assertStatus(422);

        $id = $created->json('data.id');
        $this->putJson("/api/v1/platform/notification-alert-rules/{$id}", ['name' => 'Unpaid over 5k', 'metric' => 'customer_overdue_total', 'threshold' => 5000])->assertOk()->assertJsonPath('data.name', 'Unpaid over 5k');
        expect($this->getJson('/api/v1/platform/notification-alert-rules')->json('data'))->toHaveCount(1);
        $this->deleteJson("/api/v1/platform/notification-alert-rules/{$id}")->assertNoContent();
    });

    it('alerts once when a customer overdue total crosses the threshold and again only after it dropped below', function (): void {
        $customer = biCustomer($this->tenant, 'Rossi Srl');
        NotificationAlertRule::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Unpaid over 10k', 'metric' => 'customer_overdue_total', 'threshold' => 10000, 'currency' => 'EUR', 'category' => 'billing']);

        biInvoice($this->tenant, $customer, '2027-01-01', '6000', ['payment_status' => 'overdue']);
        biInvoice($this->tenant, $customer, '2027-01-02', '3000', ['payment_status' => 'overdue']);
        expect(app(AlertRuleEvaluator::class)->evaluate($this->tenant->id))->toBe(0);

        $b = biInvoice($this->tenant, $customer, '2027-01-03', '2000', ['payment_status' => 'overdue']);
        expect(app(AlertRuleEvaluator::class)->evaluate($this->tenant->id))->toBe(1)
            ->and($this->owner->notifications()->count())->toBe(1)
            ->and($this->owner->notifications()->first()->data['title'])->toBe('Unpaid over 10k');

        // still above: no second alert
        expect(app(AlertRuleEvaluator::class)->evaluate($this->tenant->id))->toBe(0);

        // paid below the threshold rearms the rule, crossing again alerts again
        // (an issued invoice is immutable through the model, so the payment is simulated on the table)
        DB::table('invoices')->where('id', $b->id)->update(['status' => 'paid']);
        expect(app(AlertRuleEvaluator::class)->evaluate($this->tenant->id))->toBe(0);
        DB::table('invoices')->where('id', $b->id)->update(['status' => 'posted']);
        expect(app(AlertRuleEvaluator::class)->evaluate($this->tenant->id))->toBe(1);
    });

    it('addresses only members of the tenant', function (): void {
        $other = biTenant('Beta');
        $foreign = biUser($other, 'owner');
        $customer = biCustomer($this->tenant, 'Verdi Srl');
        NotificationAlertRule::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Limit', 'metric' => 'customer_overdue_total', 'threshold' => 100, 'currency' => 'EUR', 'category' => 'billing', 'recipient_user_ids' => [$foreign->id]]);
        biInvoice($this->tenant, $customer, '2027-01-01', '500', ['payment_status' => 'overdue']);

        app(AlertRuleEvaluator::class)->evaluate($this->tenant->id);

        expect($foreign->notifications()->count())->toBe(0);
    });
});
