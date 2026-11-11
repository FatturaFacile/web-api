<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Jobs\RelayOutboxEventsJob;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Enterprise\EventBus\Services\EventDispatcher;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Integrations\Models\WebhookDelivery;
use Modules\Integrations\Models\WebhookDeliveryAttempt;
use Modules\Integrations\Models\WebhookEndpoint;
use Modules\Integrations\Webhooks\WebhookSigner;
use Modules\Invoicing\Events\SalesOrderApprovedEvent;

require_once __DIR__.'/WebhookHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenantA = complianceTenant('Alfa');
    $this->tenantB = complianceTenant('Beta');
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('ok', 200)]);
});

/**
 * @return list<string> urls of the requests sent so far
 */
function sentUrls(): array
{
    return collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->url())->values()->all();
}

function approvedOrderEventFor(string $tenantId): SalesOrderApprovedEvent
{
    return new SalesOrderApprovedEvent(salesOrderId: (string) Str::uuid7(), approvedBy: (string) Str::uuid7(), tenantId: $tenantId, companyId: $tenantId);
}

test('an in-process event reaches only the endpoints of its own tenant', function (): void {
    $a = webhookEndpointFor($this->tenantA, ['sales.order.approved'], ['url' => 'https://alfa.example.com/hook']);
    $b = webhookEndpointFor($this->tenantB, ['sales.order.approved'], ['url' => 'https://beta.example.com/hook']);

    Event::dispatch(approvedOrderEventFor($this->tenantA->id));

    expect(sentUrls())->toBe(['https://alfa.example.com/hook'])
        ->and(WebhookDelivery::withoutGlobalScopes()->where('webhook_endpoint_id', $b->id)->count())->toBe(0);

    Http::assertSent(function (Request $request) use ($a): bool {
        $payload = json_decode($request->body(), true);

        return $payload['tenant_id'] === $a->tenant_id && WebhookSigner::verify($request->header('X-FF-Webhook-Signature')[0], $request->body(), $a->secret);
    });

    Event::dispatch(approvedOrderEventFor($this->tenantB->id));

    expect(sentUrls())->toBe(['https://alfa.example.com/hook', 'https://beta.example.com/hook']);

    // Tenant B's delivery is signed with B's secret only, and carries B's tenant.
    $beta = WebhookDelivery::withoutGlobalScopes()->where('webhook_endpoint_id', $b->id)->sole();
    expect($beta->tenant_id)->toBe($this->tenantB->id)->and($beta->payload['tenant_id'])->toBe($this->tenantB->id);
});

test('an event of the outbox reaches only the endpoints of its own tenant', function (): void {
    webhookEndpointFor($this->tenantA, ['treasury.collection.received'], ['url' => 'https://alfa.example.com/hook']);
    webhookEndpointFor($this->tenantB, ['treasury.collection.received'], ['url' => 'https://beta.example.com/hook']);

    Event::dispatch('treasury.collection.received', [collectionReceivedEventFor($this->tenantB->id)->toPayload()]);

    expect(sentUrls())->toBe(['https://beta.example.com/hook']);
    expect(WebhookDelivery::withoutGlobalScopes()->sole()->tenant_id)->toBe($this->tenantB->id);
});

test('the tenant of the event decides, not the tenant active when the listener runs', function (): void {
    webhookEndpointFor($this->tenantA, ['sales.order.approved'], ['url' => 'https://alfa.example.com/hook']);
    webhookEndpointFor($this->tenantB, ['sales.order.approved'], ['url' => 'https://beta.example.com/hook']);

    // A request of tenant A (explicit context) raises an event of tenant B.
    app(TenantContext::class)->runWithTenant($this->tenantA, fn () => Event::dispatch(approvedOrderEventFor($this->tenantB->id)));

    expect(sentUrls())->toBe(['https://beta.example.com/hook'])
        ->and(WebhookDelivery::withoutGlobalScopes()->sole()->tenant_id)->toBe($this->tenantB->id);
});

test('nothing is sent for an event nobody subscribed to, a disabled endpoint, or an event without a valid tenant', function (): void {
    webhookEndpointFor($this->tenantA, ['treasury.collection.received']);
    webhookEndpointFor($this->tenantA, ['sales.order.approved'], ['status' => 'disabled']);

    Event::dispatch(approvedOrderEventFor($this->tenantA->id));
    ingestWebhookEvent(approvedOrderEventFor((string) Str::uuid7()));
    ingestWebhookEvent(approvedOrderEventFor('not-a-uuid'));
    ingestWebhookEvent(approvedOrderEventFor(''));
    Event::dispatch('treasury.collection.received', [[...collectionReceivedEventFor($this->tenantA->id)->toPayload(), 'tenantId' => '']]);
    Event::dispatch('treasury.collection.received', [['collectionId' => 'x']]);

    expect(WebhookDelivery::withoutGlobalScopes()->count())->toBe(0);
    Http::assertNothingSent();
});

test('one event reaches every active endpoint of the tenant that subscribed, each with its own delivery and key', function (): void {
    webhookEndpointFor($this->tenantA, ['sales.order.approved'], ['url' => 'https://one.example.com/hook']);
    webhookEndpointFor($this->tenantA, ['sales.order.approved', 'treasury.collection.received'], ['url' => 'https://two.example.com/hook']);
    webhookEndpointFor($this->tenantA, ['treasury.collection.received'], ['url' => 'https://three.example.com/hook']);

    Event::dispatch(approvedOrderEventFor($this->tenantA->id));

    $deliveries = WebhookDelivery::withoutGlobalScopes()->get();

    expect(sentUrls())->toEqualCanonicalizing(['https://one.example.com/hook', 'https://two.example.com/hook'])
        ->and($deliveries->pluck('idempotency_key')->unique())->toHaveCount(2)
        ->and($deliveries->pluck('event_id')->unique())->toHaveCount(1);
});

test('a rolled-back operation sends nothing', function (): void {
    webhookEndpointFor($this->tenantA, ['sales.order.approved']);

    try {
        DB::transaction(function (): void {
            Event::dispatch(approvedOrderEventFor($this->tenantA->id));

            throw new RuntimeException('the business operation fails after raising the event');
        });
    } catch (RuntimeException) {
    }

    expect(WebhookDelivery::withoutGlobalScopes()->count())->toBe(0);
    Http::assertNothingSent();
});

test('an event published to the outbox is delivered once by the relay, without double delivery for in-process events', function (): void {
    webhookEndpointFor($this->tenantA, ['treasury.collection.received', 'sales.order.approved']);
    $dispatcher = app(EventDispatcher::class);

    // Channel "outbox": only published; the relay dispatches it.
    $dispatcher->publish(collectionReceivedEventFor($this->tenantA->id));
    expect(WebhookDelivery::withoutGlobalScopes()->count())->toBe(0);

    (new RelayOutboxEventsJob)->handle();

    expect(WebhookDelivery::withoutGlobalScopes()->where('event_name', 'treasury.collection.received')->count())->toBe(1);

    // Channel "class": published AND dispatched in-process; the relay later replays it by name and must add nothing.
    $dispatcher->publishAndDispatch(approvedOrderEventFor($this->tenantA->id));
    expect(WebhookDelivery::withoutGlobalScopes()->where('event_name', 'sales.order.approved')->count())->toBe(1);

    (new RelayOutboxEventsJob)->handle();
    (new RelayOutboxEventsJob)->handle();

    expect(WebhookDelivery::withoutGlobalScopes()->where('event_name', 'sales.order.approved')->count())->toBe(1)
        ->and(WebhookDelivery::withoutGlobalScopes()->where('event_name', 'treasury.collection.received')->count())->toBe(1)
        ->and(TransactionalOutbox::query()->where('status', TransactionalOutbox::STATUS_PENDING)->count())->toBe(0);
});

test('an outbox event the relay delivers twice (at-least-once) is sent once to the endpoint', function (): void {
    webhookEndpointFor($this->tenantA, ['treasury.collection.received']);
    $payload = collectionReceivedEventFor($this->tenantA->id)->toPayload();

    Event::dispatch('treasury.collection.received', [$payload]);
    Event::dispatch('treasury.collection.received', [$payload]);

    expect(WebhookDelivery::withoutGlobalScopes()->count())->toBe(1);

    // A different event of the same kind is not a repeat.
    Event::dispatch('treasury.collection.received', [collectionReceivedEventFor($this->tenantA->id)->toPayload()]);

    expect(WebhookDelivery::withoutGlobalScopes()->count())->toBe(2);
});

test('a failure while creating deliveries never breaks the code that raised the event', function (): void {
    webhookEndpointFor($this->tenantA, ['sales.order.approved'], ['url' => 'https://broken.example.com/hook', 'payload_version' => 'v9']);

    $result = DB::transaction(function (): string {
        Event::dispatch(approvedOrderEventFor($this->tenantA->id));

        return (string) DB::table('tenants')->where('id', $this->tenantA->id)->value('id');
    });

    // The caller went on and its transaction is intact; the ingestion of this event was abandoned.
    expect($result)->toBe($this->tenantA->id)->and(WebhookDelivery::withoutGlobalScopes()->count())->toBe(0);
});

test('the webhook tables are tenant scoped models', function (): void {
    foreach ([WebhookEndpoint::class, WebhookDelivery::class, WebhookDeliveryAttempt::class] as $model) {
        expect(class_uses_recursive($model))->toContain(BelongsToTenant::class);
    }
});
