<?php

declare(strict_types=1);

use App\Contracts\Security\HostResolver;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Integrations\Models\WebhookDelivery;
use Modules\Integrations\Models\WebhookEndpoint;
use Modules\Integrations\Webhooks\WebhookSigner;
use Tests\Support\StaticHostResolver;

require_once __DIR__.'/WebhookHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    $this->user = actingAsMember($this->tenant);
    Http::preventStrayRequests();
});

function createEndpointPayload(array $overrides = []): array
{
    return [
        'name' => 'Data warehouse',
        'url' => 'https://hooks.example.com/fattura-facile',
        'events' => ['invoicing.invoice.posted', 'treasury.collection.received'],
        ...$overrides,
    ];
}

test('the catalogue lists only events the application raises, with their data fields and the signature scheme', function (): void {
    $data = $this->getJson('/api/v1/integrations/webhook-events')->assertOk()->json('data');

    $names = collect($data['events'])->pluck('name');

    expect($names)->toContain('invoicing.invoice.posted', 'sales.order.approved', 'treasury.collection.received', 'inventory.reorder_point_reached', 'esm.ticket.created', 'crm.lead.created')
        ->and($data['payload_versions'])->toBe(['v1'])
        ->and($data['signature']['header'])->toBe('X-FF-Webhook-Signature')
        ->and(collect($data['events'])->firstWhere('name', 'invoicing.invoice.posted')['data_fields'])->toHaveKey('total_gross');
});

test('creating an endpoint returns the secret once and never again', function (): void {
    $created = $this->postJson('/api/v1/integrations/webhook-endpoints', createEndpointPayload())->assertCreated()->json('data');

    expect($created['secret'])->toStartWith('whsec_')->and($created['status'])->toBe('active')->and($created['secret_hint'])->toBe(substr($created['secret'], -4));

    // The secret is encrypted at rest: it is not in the table as plain text.
    expect(DB::table('webhook_endpoints')->where('id', $created['id'])->value('secret'))->not->toContain($created['secret']);

    foreach (['/api/v1/integrations/webhook-endpoints', "/api/v1/integrations/webhook-endpoints/{$created['id']}"] as $url) {
        expect($this->getJson($url)->assertOk()->getContent())->not->toContain($created['secret'])->not->toContain('"previous_secret"');
    }

    expect($this->getJson("/api/v1/integrations/webhook-endpoints/{$created['id']}")->json('data.secret'))->toBeNull();
});

test('an unsafe or malformed endpoint is refused with a validation error', function (array $overrides, string $field): void {
    $this->postJson('/api/v1/integrations/webhook-endpoints', createEndpointPayload($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(WebhookEndpoint::query()->count())->toBe(0);
})->with([
    'http url' => [['url' => 'http://hooks.example.com/x'], 'url'],
    'loopback' => [['url' => 'https://127.0.0.1/x'], 'url'],
    'private network' => [['url' => 'https://10.1.2.3/x'], 'url'],
    'cloud metadata' => [['url' => 'https://169.254.169.254/latest/meta-data'], 'url'],
    'localhost' => [['url' => 'https://localhost/x'], 'url'],
    'credentials' => [['url' => 'https://u:p@hooks.example.com/x'], 'url'],
    'bad port' => [['url' => 'https://hooks.example.com:22/x'], 'url'],
    'ipv6 loopback' => [['url' => 'https://[::1]/x'], 'url'],
    'unknown event' => [['events' => ['invoicing.invoice.exploded']], 'events.0'],
    'no events' => [['events' => []], 'events'],
    'duplicate events' => [['events' => ['invoicing.invoice.posted', 'invoicing.invoice.posted']], 'events.0'],
    'unknown version' => [['payload_version' => 'v9'], 'payload_version'],
]);

test('a URL that resolves to a private address is refused on creation and on update', function (): void {
    app()->instance(HostResolver::class, new StaticHostResolver(['evil.example.com' => ['93.184.215.14', '192.168.0.10']]));

    $this->postJson('/api/v1/integrations/webhook-endpoints', createEndpointPayload(['url' => 'https://evil.example.com/x']))->assertUnprocessable()->assertJsonValidationErrors('url');

    $endpoint = webhookEndpointFor($this->tenant);
    $this->putJson("/api/v1/integrations/webhook-endpoints/{$endpoint->id}", ['url' => 'https://evil.example.com/x'])->assertUnprocessable()->assertJsonValidationErrors('url');
    expect($endpoint->refresh()->url)->not->toContain('evil');
});

test('an endpoint can be updated and deleted', function (): void {
    $endpoint = webhookEndpointFor($this->tenant);

    $this->putJson("/api/v1/integrations/webhook-endpoints/{$endpoint->id}", ['name' => 'Renamed', 'events' => ['sales.order.approved']])->assertOk()->assertJsonPath('data.name', 'Renamed');
    expect($endpoint->refresh()->events)->toBe(['sales.order.approved']);

    $this->deleteJson("/api/v1/integrations/webhook-endpoints/{$endpoint->id}")->assertOk();
    expect(WebhookEndpoint::withoutGlobalScopes()->count())->toBe(0);
});

test('the number of endpoints per tenant is limited', function (): void {
    config(['webhooks.max_endpoints_per_tenant' => 2]);

    $this->postJson('/api/v1/integrations/webhook-endpoints', createEndpointPayload())->assertCreated();
    $this->postJson('/api/v1/integrations/webhook-endpoints', createEndpointPayload())->assertCreated();
    $this->postJson('/api/v1/integrations/webhook-endpoints', createEndpointPayload())->assertUnprocessable();
});

test('rotating the secret keeps the previous one signing for the overlap, then only the new one', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $endpoint = webhookEndpointFor($this->tenant);
    $old = $endpoint->secret;

    $rotated = $this->postJson("/api/v1/integrations/webhook-endpoints/{$endpoint->id}/rotate-secret", ['overlap_hours' => 2])->assertOk()->json('data');
    $new = $rotated['secret'];

    expect($new)->toStartWith('whsec_')->not->toBe($old)->and($rotated['previous_secret_expires_at'])->not->toBeNull();

    $this->postJson("/api/v1/integrations/webhook-endpoints/{$endpoint->id}/test")->assertOk();

    Http::assertSent(function ($request) use ($old, $new): bool {
        $header = $request->header(WebhookSigner::SignatureHeader)[0];
        $body = $request->body();

        return substr_count($header, 'v1=') === 2 && WebhookSigner::verify($header, $body, $new) && WebhookSigner::verify($header, $body, $old);
    });

    // After the overlap only the new secret signs.
    $this->travel(3)->hours();
    $this->postJson("/api/v1/integrations/webhook-endpoints/{$endpoint->id}/test")->assertOk();

    Http::assertSent(function ($request) use ($old, $new): bool {
        $header = $request->header(WebhookSigner::SignatureHeader)[0];

        return substr_count($header, 'v1=') === 1 && WebhookSigner::verify($header, $request->body(), $new, 600, time()) && ! WebhookSigner::verify($header, $request->body(), $old, 600, time());
    });
});

test('a rotation without overlap retires the old secret at once', function (): void {
    $endpoint = webhookEndpointFor($this->tenant);

    $this->postJson("/api/v1/integrations/webhook-endpoints/{$endpoint->id}/rotate-secret", ['overlap_hours' => 0])->assertOk()->assertJsonPath('data.previous_secret_expires_at', null);

    expect($endpoint->refresh()->signingSecrets())->toHaveCount(1);
    $this->postJson("/api/v1/integrations/webhook-endpoints/{$endpoint->id}/rotate-secret", ['overlap_hours' => 999])->assertUnprocessable();
});

test('the test ping is sent at once, signed, and its outcome returned', function (): void {
    Http::fake(['*' => Http::response('pong', 202)]);
    $endpoint = webhookEndpointFor($this->tenant);

    $delivery = $this->postJson("/api/v1/integrations/webhook-endpoints/{$endpoint->id}/test")->assertOk()->json('data');

    expect($delivery['status'])->toBe('succeeded')->and($delivery['is_test'])->toBeTrue()->and($delivery['last_response_code'])->toBe(202)
        ->and($delivery['last_duration_ms'])->toBeInt()->and($delivery['payload']['type'])->toBe('webhook.ping')
        ->and($delivery['attempt_log'])->toHaveCount(1)->and($delivery['attempt_log'][0]['response_excerpt'])->toBe('pong');

    Http::assertSent(fn ($request): bool => $request->url() === $endpoint->url
        && $request->header('X-FF-Webhook-Event')[0] === 'webhook.ping'
        && WebhookSigner::verify($request->header(WebhookSigner::SignatureHeader)[0], $request->body(), $endpoint->secret));
});

test('a failing test ping is reported, not retried, and does not count towards the automatic disable', function (): void {
    Http::fake(['*' => Http::response('nope', 500)]);
    $endpoint = webhookEndpointFor($this->tenant);

    $delivery = $this->postJson("/api/v1/integrations/webhook-endpoints/{$endpoint->id}/test")->assertOk()->json('data');

    expect($delivery['status'])->toBe('dead')->and($delivery['last_response_code'])->toBe(500)->and($delivery['attempts'])->toBe(1)
        ->and($endpoint->refresh()->consecutive_failures)->toBe(0);
    Http::assertSentCount(1);
});

test('a ping to an address that turned private after the endpoint was saved is blocked, not sent', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $endpoint = webhookEndpointFor($this->tenant, attributes: ['url' => 'https://rebinding.example.com/hook']);
    app()->instance(HostResolver::class, new StaticHostResolver(['rebinding.example.com' => ['169.254.169.254']]));

    $delivery = $this->postJson("/api/v1/integrations/webhook-endpoints/{$endpoint->id}/test")->assertOk()->json('data');

    expect($delivery['status'])->toBe('dead')->and($delivery['last_error'])->toStartWith('Blocked:')->and($delivery['last_response_code'])->toBeNull();
    Http::assertNothingSent();
});

test('disabling and enabling an endpoint', function (): void {
    $endpoint = webhookEndpointFor($this->tenant, attributes: ['consecutive_failures' => 7]);

    $this->postJson("/api/v1/integrations/webhook-endpoints/{$endpoint->id}/disable")->assertOk()->assertJsonPath('data.status', 'disabled');
    $this->postJson("/api/v1/integrations/webhook-endpoints/{$endpoint->id}/enable")->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.consecutive_failures', 0);
});

test('every sensitive action is written to the audit trail, and no secret is', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $created = $this->postJson('/api/v1/integrations/webhook-endpoints', createEndpointPayload())->assertCreated()->json('data');
    $id = $created['id'];
    $rotated = $this->postJson("/api/v1/integrations/webhook-endpoints/{$id}/rotate-secret")->assertOk()->json('data.secret');
    $this->putJson("/api/v1/integrations/webhook-endpoints/{$id}", ['name' => 'X'])->assertOk();
    $this->postJson("/api/v1/integrations/webhook-endpoints/{$id}/test")->assertOk();
    $this->postJson("/api/v1/integrations/webhook-endpoints/{$id}/disable")->assertOk();
    $this->postJson("/api/v1/integrations/webhook-endpoints/{$id}/enable")->assertOk();
    $this->deleteJson("/api/v1/integrations/webhook-endpoints/{$id}")->assertOk();

    $entries = AuditLedgerEntry::query()->where('tenant_id', $this->tenant->id)->where('action', 'like', 'INTEGRATIONS_WEBHOOK_%')->get();

    expect($entries->pluck('action')->all())->toContain(
        'INTEGRATIONS_WEBHOOK_ENDPOINT_CREATED', 'INTEGRATIONS_WEBHOOK_SECRET_ROTATED', 'INTEGRATIONS_WEBHOOK_ENDPOINT_UPDATED',
        'INTEGRATIONS_WEBHOOK_TEST_PING_SENT', 'INTEGRATIONS_WEBHOOK_ENDPOINT_DISABLED', 'INTEGRATIONS_WEBHOOK_ENDPOINT_ENABLED', 'INTEGRATIONS_WEBHOOK_ENDPOINT_DELETED',
    );

    $dump = $entries->toJson();
    expect($dump)->not->toContain($created['secret'])->not->toContain($rotated);
});

test('redelivery needs an active endpoint and a delivery that is not in progress', function (): void {
    Http::fake(['*' => Http::response('', 200)]);
    $endpoint = webhookEndpointFor($this->tenant);
    $dead = WebhookDelivery::factory()->create(['tenant_id' => $this->tenant->id, 'webhook_endpoint_id' => $endpoint->id, 'status' => 'dead', 'attempts' => 8, 'next_attempt_at' => null]);
    $retrying = WebhookDelivery::factory()->create(['tenant_id' => $this->tenant->id, 'webhook_endpoint_id' => $endpoint->id, 'status' => 'retrying', 'next_attempt_at' => now()->addHour()]);

    $this->postJson("/api/v1/integrations/webhook-deliveries/{$retrying->id}/redeliver")->assertUnprocessable();

    $this->postJson("/api/v1/integrations/webhook-endpoints/{$endpoint->id}/disable")->assertOk();
    $this->postJson("/api/v1/integrations/webhook-deliveries/{$dead->id}/redeliver")->assertUnprocessable()->assertJsonValidationErrors('endpoint');

    $this->postJson("/api/v1/integrations/webhook-endpoints/{$endpoint->id}/enable")->assertOk();
    $this->postJson("/api/v1/integrations/webhook-deliveries/{$dead->id}/redeliver")->assertStatus(202);

    // The queue is synchronous in tests: the delivery has been sent again, from its first attempt, with its own key.
    expect($dead->refresh()->status)->toBe('succeeded')->and($dead->attempts)->toBe(1);
    Http::assertSent(fn ($request): bool => $request->header('X-FF-Webhook-Id')[0] === $dead->idempotency_key);
});

test('the delivery log can be filtered and shows one delivery with its attempts', function (): void {
    $endpoint = webhookEndpointFor($this->tenant);
    $dead = WebhookDelivery::factory()->create(['tenant_id' => $this->tenant->id, 'webhook_endpoint_id' => $endpoint->id, 'status' => 'dead']);
    WebhookDelivery::factory()->create(['tenant_id' => $this->tenant->id, 'webhook_endpoint_id' => $endpoint->id, 'status' => 'succeeded']);

    expect($this->getJson('/api/v1/integrations/webhook-deliveries')->assertOk()->json('data'))->toHaveCount(2)
        ->and($this->getJson('/api/v1/integrations/webhook-deliveries?status=dead')->json('data'))->toHaveCount(1)
        ->and($this->getJson("/api/v1/integrations/webhook-deliveries?endpoint_id={$endpoint->id}")->json('data'))->toHaveCount(2)
        ->and(true)->toBeTrue();

    $this->getJson('/api/v1/integrations/webhook-deliveries?status=bogus')->assertUnprocessable();

    $this->getJson("/api/v1/integrations/webhook-deliveries/{$dead->id}")->assertOk()->assertJsonPath('data.payload.api_version', 'v1');
});

test('only owners and admins reach the webhook endpoints', function (): void {
    $endpoint = webhookEndpointFor($this->tenant);
    actingAsMember($this->tenant, 'member');

    $this->getJson('/api/v1/integrations/webhook-endpoints')->assertForbidden();
    $this->postJson('/api/v1/integrations/webhook-endpoints', createEndpointPayload())->assertForbidden();
    $this->postJson("/api/v1/integrations/webhook-endpoints/{$endpoint->id}/test")->assertForbidden();
    $this->getJson('/api/v1/integrations/webhook-events')->assertForbidden();
    $this->getJson('/api/v1/integrations/webhook-deliveries')->assertForbidden();
});

test('an anonymous caller is rejected', function (): void {
    $this->app['auth']->forgetGuards();
    $this->withHeader('Authorization', '')->getJson('/api/v1/integrations/webhook-endpoints')->assertUnauthorized();
});

test('a tenant cannot see or touch the webhook endpoints and deliveries of another tenant', function (): void {
    $other = complianceTenant('Beta');
    $theirs = webhookEndpointFor($other);
    $theirDelivery = WebhookDelivery::factory()->create(['tenant_id' => $other->id, 'webhook_endpoint_id' => $theirs->id, 'status' => 'dead']);
    webhookEndpointFor($this->tenant);

    expect($this->getJson('/api/v1/integrations/webhook-endpoints')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/integrations/webhook-deliveries')->json('data'))->toHaveCount(0);

    $this->getJson("/api/v1/integrations/webhook-endpoints/{$theirs->id}")->assertNotFound();
    $this->putJson("/api/v1/integrations/webhook-endpoints/{$theirs->id}", ['name' => 'Hijacked'])->assertNotFound();
    $this->deleteJson("/api/v1/integrations/webhook-endpoints/{$theirs->id}")->assertNotFound();
    $this->postJson("/api/v1/integrations/webhook-endpoints/{$theirs->id}/rotate-secret")->assertNotFound();
    $this->postJson("/api/v1/integrations/webhook-endpoints/{$theirs->id}/test")->assertNotFound();
    $this->postJson("/api/v1/integrations/webhook-endpoints/{$theirs->id}/enable")->assertNotFound();
    $this->postJson("/api/v1/integrations/webhook-endpoints/{$theirs->id}/disable")->assertNotFound();
    $this->getJson("/api/v1/integrations/webhook-deliveries/{$theirDelivery->id}")->assertNotFound();
    $this->postJson("/api/v1/integrations/webhook-deliveries/{$theirDelivery->id}/redeliver")->assertNotFound();
    $this->postJson('/api/v1/integrations/webhook-deliveries/'.Str::uuid().'/redeliver')->assertNotFound();

    expect(WebhookEndpoint::withoutGlobalScopes()->whereKey($theirs->id)->value('name'))->not->toBe('Hijacked');
});
