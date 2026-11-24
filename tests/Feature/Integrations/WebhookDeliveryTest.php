<?php

declare(strict_types=1);

use App\Contracts\Security\HostResolver;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Integrations\Jobs\DeliverWebhookJob;
use Modules\Integrations\Models\WebhookDelivery;
use Modules\Integrations\Models\WebhookDeliveryAttempt;
use Modules\Integrations\Webhooks\WebhookDeliveryService;
use Modules\Integrations\Webhooks\WebhookSigner;
use Tests\Support\StaticHostResolver;

require_once __DIR__.'/WebhookHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    Http::preventStrayRequests();
    RateLimiter::clear('webhooks:tenant:'.$this->tenant->id);
});

test('an event is delivered signed, with the idempotency key, the version and the documented envelope', function (): void {
    Http::fake(['*' => Http::response('received', 200)]);
    $endpoint = webhookEndpointFor($this->tenant);
    $event = webhookInvoicePostedEvent($this->tenant->id, 'FT-42');

    ingestWebhookEvent($event);

    $delivery = WebhookDelivery::withoutGlobalScopes()->sole();

    expect($delivery->status)->toBe('succeeded')->and($delivery->attempts)->toBe(1)->and($delivery->last_response_code)->toBe(200)
        ->and($delivery->last_duration_ms)->toBeInt()->and($delivery->delivered_at)->not->toBeNull()
        ->and($delivery->payload['tenant_id'])->toBe($this->tenant->id)->and($delivery->payload['type'])->toBe('invoicing.invoice.posted')
        ->and($delivery->payload['api_version'])->toBe('v1')->and($delivery->payload['data']['invoice_number'])->toBe('FT-42')
        ->and($delivery->payload['data'])->not->toHaveKeys(['tenant_id', 'company_id'])
        ->and(WebhookDeliveryAttempt::withoutGlobalScopes()->where('webhook_delivery_id', $delivery->id)->count())->toBe(1);

    Http::assertSent(function (Request $request) use ($endpoint, $delivery): bool {
        $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

        return $request->url() === $endpoint->url
            && $request->method() === 'POST'
            && $request->header('X-FF-Webhook-Id')[0] === $delivery->idempotency_key
            && $request->header('X-FF-Webhook-Event')[0] === 'invoicing.invoice.posted'
            && $request->header('X-FF-Webhook-Version')[0] === 'v1'
            && $request->header('Content-Type')[0] === 'application/json'
            && WebhookSigner::verify($request->header(WebhookSigner::SignatureHeader)[0], $request->body(), $endpoint->secret)
            && ! WebhookSigner::verify($request->header(WebhookSigner::SignatureHeader)[0], $request->body(), $endpoint->secret.'x')
            && $payload['id'] === $delivery->event_id
            && $payload['data']['invoice_id'] === $delivery->payload['data']['invoice_id'];
    });
});

test('a redirect is never followed and counts as a failure', function (): void {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data'])]);
    $endpoint = webhookEndpointFor($this->tenant);

    ingestWebhookEvent(webhookInvoicePostedEvent($this->tenant->id));

    $delivery = WebhookDelivery::withoutGlobalScopes()->sole();

    expect($delivery->status)->toBe('retrying')->and($delivery->last_response_code)->toBe(302)->and($delivery->last_error)->toContain('redirect');
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '169.254.169.254'));
    expect($endpoint->refresh()->consecutive_failures)->toBe(1);
});

test('a host that resolves to a private address at delivery time is blocked and nothing is sent', function (): void {
    Http::fake(['*' => Http::response('', 200)]);
    webhookEndpointFor($this->tenant, attributes: ['url' => 'https://later-private.example.com/hook']);
    app()->instance(HostResolver::class, new StaticHostResolver(['later-private.example.com' => ['10.0.0.9']]));

    ingestWebhookEvent(webhookInvoicePostedEvent($this->tenant->id));

    $delivery = WebhookDelivery::withoutGlobalScopes()->sole();

    expect($delivery->status)->toBe('retrying')->and($delivery->last_error)->toStartWith('Blocked:')->and($delivery->last_response_code)->toBeNull();
    Http::assertNothingSent();
});

test('failed attempts back off exponentially, keep the same idempotency key, and a later success resets the endpoint', function (): void {
    config(['webhooks.backoff_base_seconds' => 30, 'webhooks.backoff_max_seconds' => 21600]);
    Http::fakeSequence()->push('boom', 500)->push('unavailable', 503)->push('ok', 200);
    $endpoint = webhookEndpointFor($this->tenant);
    $this->travelTo(now()->startOfSecond());

    ingestWebhookEvent(webhookInvoicePostedEvent($this->tenant->id));
    $delivery = WebhookDelivery::withoutGlobalScopes()->sole();

    // First failure: next attempt in 30 s (+ up to 10 % of jitter).
    expect($delivery->status)->toBe('retrying')->and($delivery->attempts)->toBe(1)
        ->and((int) now()->diffInSeconds($delivery->next_attempt_at, false))->toBeBetween(30, 33)
        ->and($delivery->last_response_code)->toBe(500);

    // Not due yet: nothing is sent.
    expect(webhookProcessDelivery($delivery)->attempts)->toBe(1);
    Http::assertSentCount(1);

    $this->travel(40)->seconds();
    $delivery = webhookProcessDelivery($delivery);

    // Second failure: 60 s.
    expect($delivery->status)->toBe('retrying')->and($delivery->attempts)->toBe(2)
        ->and((int) now()->diffInSeconds($delivery->next_attempt_at, false))->toBeBetween(60, 66)
        ->and($endpoint->refresh()->consecutive_failures)->toBe(2);

    $this->travel(70)->seconds();
    $delivery = webhookProcessDelivery($delivery);

    expect($delivery->status)->toBe('succeeded')->and($delivery->attempts)->toBe(3)->and($delivery->next_attempt_at)->toBeNull()
        ->and($endpoint->refresh()->consecutive_failures)->toBe(0)->and($endpoint->last_success_at)->not->toBeNull();

    $keys = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->header('X-FF-Webhook-Id')[0])->unique();
    expect($keys->all())->toBe([$delivery->idempotency_key])
        ->and(WebhookDeliveryAttempt::withoutGlobalScopes()->where('webhook_delivery_id', $delivery->id)->orderBy('attempt_number')->pluck('response_code')->all())->toBe([500, 503, 200]);
});

test('the backoff doubles and is capped', function (): void {
    config(['webhooks.backoff_base_seconds' => 30, 'webhooks.backoff_max_seconds' => 21600]);
    $service = app(WebhookDeliveryService::class);

    expect(array_map($service->backoffSeconds(...), [1, 2, 3, 4, 8, 20, 500]))->toBe([30, 60, 120, 240, 3840, 21600, 21600]);
});

test('after the last attempt a delivery is a dead letter, kept in the log, and is not retried any more', function (): void {
    config(['webhooks.max_attempts' => 3]);
    Http::fake(['*' => Http::response('down', 500)]);
    $endpoint = webhookEndpointFor($this->tenant);

    ingestWebhookEvent(webhookInvoicePostedEvent($this->tenant->id));
    $delivery = WebhookDelivery::withoutGlobalScopes()->sole();

    foreach ([1, 2] as $round) {
        expect($delivery->status)->toBe('retrying')->and($delivery->attempts)->toBe($round);
        $this->travel(3)->hours();
        $delivery = webhookProcessDelivery($delivery);
    }

    expect($delivery->status)->toBe('dead')->and($delivery->attempts)->toBe(3)->and($delivery->next_attempt_at)->toBeNull()
        ->and($delivery->last_error)->toContain('HTTP 500')
        ->and($endpoint->refresh()->consecutive_failures)->toBe(3)->and($endpoint->status)->toBe('active');

    Http::assertSentCount(3);

    // Nothing more happens by itself.
    $this->travel(30)->hours();
    webhookProcessDelivery($delivery);
    $this->artisan('webhooks:dispatch-overdue')->assertSuccessful();
    Http::assertSentCount(3);
    expect(WebhookDelivery::withoutGlobalScopes()->whereKey($delivery->id)->value('status'))->toBe('dead');
});

test('a dead letter can be redelivered by hand with the same idempotency key', function (): void {
    config(['webhooks.max_attempts' => 1]);
    Http::fakeSequence()->push('down', 500)->push('ok', 200);
    webhookEndpointFor($this->tenant);
    $actor = actingAsMember($this->tenant);

    ingestWebhookEvent(webhookInvoicePostedEvent($this->tenant->id));
    $delivery = WebhookDelivery::withoutGlobalScopes()->sole();
    expect($delivery->status)->toBe('dead');

    $this->postJson("/api/v1/integrations/webhook-deliveries/{$delivery->id}/redeliver")->assertStatus(202);

    $delivery->refresh();
    expect($delivery->status)->toBe('succeeded')->and($delivery->attempts)->toBe(1);

    $keys = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->header('X-FF-Webhook-Id')[0])->unique()->all();
    expect($keys)->toBe([$delivery->idempotency_key])
        ->and(AuditLedgerEntry::query()->where('action', 'INTEGRATIONS_WEBHOOK_DELIVERY_REDELIVERED')->count())->toBe(1)
        ->and($actor->id)->not->toBeNull();
});

test('an endpoint that keeps failing is disabled, its waiting deliveries become dead letters, and owners and admins are notified', function (): void {
    config(['webhooks.auto_disable_after_failures' => 3, 'webhooks.max_attempts' => 10]);
    Http::fake(['*' => Http::response('down', 500)]);
    $owner = actingAsMember($this->tenant, 'owner');
    $admin = actingAsMember($this->tenant, 'admin');
    $member = actingAsMember($this->tenant, 'member');
    $endpoint = webhookEndpointFor($this->tenant);

    ingestWebhookEvent(webhookInvoicePostedEvent($this->tenant->id, 'FT-1'));
    ingestWebhookEvent(webhookInvoicePostedEvent($this->tenant->id, 'FT-2'));
    $first = WebhookDelivery::withoutGlobalScopes()->orderBy('created_at')->first();

    $this->travel(2)->minutes();
    webhookProcessDelivery($first);

    $endpoint->refresh();
    expect($endpoint->status)->toBe('disabled')->and($endpoint->disabled_at)->not->toBeNull()->and($endpoint->disabled_reason)->toContain('automatically')
        ->and(WebhookDelivery::withoutGlobalScopes()->whereIn('status', ['pending', 'retrying'])->count())->toBe(0)
        ->and(WebhookDelivery::withoutGlobalScopes()->where('status', 'dead')->count())->toBe(2);

    $notified = DatabaseNotification::query()->get()->filter(fn (DatabaseNotification $notification): bool => ($notification->data['activity'] ?? null) === 'webhook_endpoint_disabled')->pluck('notifiable_id')->all();
    expect($notified)->toContain($owner->id, $admin->id)->not->toContain($member->id)
        ->and(AuditLedgerEntry::query()->where('action', 'INTEGRATIONS_WEBHOOK_ENDPOINT_AUTO_DISABLED')->count())->toBe(1);

    // A disabled endpoint receives no new events.
    $sent = count(Http::recorded());
    ingestWebhookEvent(webhookInvoicePostedEvent($this->tenant->id, 'FT-3'));
    expect(count(Http::recorded()))->toBe($sent);
});

test('a delivery of an endpoint that was disabled meanwhile is not sent', function (): void {
    Http::fake(['*' => Http::response('', 200)]);
    $endpoint = webhookEndpointFor($this->tenant);
    $disabled = webhookPendingDelivery($endpoint);
    $endpoint->update(['status' => 'disabled']);

    expect(webhookProcessDelivery($disabled)->status)->toBe('dead')->and($disabled->last_error)->toContain('disabled');

    Http::assertNothingSent();
});

test('a delivery already claimed by a worker is not sent twice, a stale claim is taken over', function (): void {
    Http::fake(['*' => Http::response('', 200)]);
    $endpoint = webhookEndpointFor($this->tenant);
    $delivery = webhookPendingDelivery($endpoint, ['claimed_at' => now()]);

    expect(webhookProcessDelivery($delivery)->status)->toBe('pending');
    Http::assertNothingSent();

    $delivery->forceFill(['claimed_at' => now()->subMinutes(10)])->save();

    expect(webhookProcessDelivery($delivery)->status)->toBe('succeeded');
    Http::assertSentCount(1);

    // A job that arrives late for a delivery that already succeeded does nothing.
    webhookProcessDelivery($delivery);
    Http::assertSentCount(1);
});

test('a delivery over the per-tenant rate limit is postponed, not lost nor counted as a failure', function (): void {
    config(['webhooks.rate_limit_per_minute' => 2]);
    Http::fake(['*' => Http::response('', 200)]);
    Queue::fake();
    $endpoint = webhookEndpointFor($this->tenant);
    $deliveries = collect(range(1, 3))->map(fn (): WebhookDelivery => webhookPendingDelivery($endpoint));

    $statuses = $deliveries->map(fn (WebhookDelivery $delivery): string => webhookProcessDelivery($delivery)->status)->all();

    expect($statuses)->toBe(['succeeded', 'succeeded', 'pending']);
    Http::assertSentCount(2);
    Queue::assertPushed(DeliverWebhookJob::class, fn (DeliverWebhookJob $job): bool => $job->deliveryId === $deliveries[2]->id && $job->delay !== null);
    expect($endpoint->refresh()->consecutive_failures)->toBe(0)->and($deliveries[2]->refresh()->attempts)->toBe(0);

    // The limit is per tenant: another tenant is not affected.
    $other = complianceTenant('Beta');
    $otherDelivery = webhookPendingDelivery(webhookEndpointFor($other));

    expect(webhookProcessDelivery($otherDelivery)->status)->toBe('succeeded');

    $this->travel(61)->seconds();
    expect(webhookProcessDelivery($deliveries[2])->status)->toBe('succeeded');
});

test('the overdue sweeper queues again a due delivery whose job was lost', function (): void {
    Http::fake(['*' => Http::response('', 200)]);
    $endpoint = webhookEndpointFor($this->tenant);
    $lost = webhookPendingDelivery($endpoint, ['next_attempt_at' => now()->subMinutes(10)]);
    $fresh = webhookPendingDelivery($endpoint, ['next_attempt_at' => now()->subSeconds(5)]);

    $this->artisan('webhooks:dispatch-overdue')->assertSuccessful();

    expect($lost->refresh()->status)->toBe('succeeded')->and($fresh->refresh()->status)->toBe('pending');
});

test('old finished deliveries are pruned, open ones never', function (): void {
    $endpoint = webhookEndpointFor($this->tenant);
    $old = webhookPendingDelivery($endpoint, ['status' => 'succeeded']);
    $oldOpen = webhookPendingDelivery($endpoint, ['status' => 'retrying']);
    $recent = webhookPendingDelivery($endpoint, ['status' => 'dead']);
    DB::table('webhook_deliveries')->whereIn('id', [$old->id, $oldOpen->id])->update(['updated_at' => now()->subDays(120)]);

    $this->artisan('webhooks:prune')->assertSuccessful();

    expect(WebhookDelivery::withoutGlobalScopes()->pluck('id')->all())->toContain($oldOpen->id, $recent->id)->not->toContain($old->id);
});

test('on the database queue driver the delivery is a queued job, and the retry is a delayed one', function (): void {
    config(['queue.default' => 'database']);
    Http::fake(['*' => Http::sequence()->push('down', 500)->push('ok', 200)]);
    webhookEndpointFor($this->tenant);

    ingestWebhookEvent(webhookInvoicePostedEvent($this->tenant->id));

    $jobs = DB::table('jobs')->get();
    expect($jobs)->toHaveCount(1)->and(json_decode($jobs[0]->payload, true)['displayName'])->toBe(DeliverWebhookJob::class);
    Http::assertNothingSent();

    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

    $delivery = WebhookDelivery::withoutGlobalScopes()->sole();
    expect($delivery->status)->toBe('retrying')->and($delivery->attempts)->toBe(1);
    Http::assertSentCount(1);

    $retry = DB::table('jobs')->get();
    expect($retry)->toHaveCount(1)->and((int) $retry[0]->available_at)->toBeGreaterThan(time() + 20);

    // The delayed job is not picked up before its time.
    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();
    Http::assertSentCount(1);

    // When it is due, the worker sends the second attempt.
    $this->travel(2)->minutes();
    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

    expect($delivery->refresh()->status)->toBe('succeeded')->and($delivery->attempts)->toBe(2);
});
