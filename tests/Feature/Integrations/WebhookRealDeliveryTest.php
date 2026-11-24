<?php

declare(strict_types=1);

use App\Services\Security\OutboundUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Integrations\Models\WebhookDelivery;
use Modules\Integrations\Webhooks\WebhookDeliveryService;
use Modules\Integrations\Webhooks\WebhookSigner;
use Tests\Support\StaticHostResolver;
use Tests\Support\WebhookTestListener;

require_once __DIR__.'/WebhookHelpers.php';

uses(RefreshDatabase::class);

/*
 * Real deliveries: no Http::fake. A throwaway HTTPS listener runs in a separate PHP process on 127.0.0.1 (see
 * tests/Support/WebhookTestServer.php) with a self-signed certificate made for the test.
 *
 * Two test-only switches make loopback reachable, and both are explicit in the test:
 * - the OutboundUrlGuard of the container is replaced by one built with an allow-list holding 127.0.0.1 (the guard
 *   constructor parameter that the application fills only in the local environment); in production, and in the
 *   testing environment by default, loopback is refused;
 * - the self-signed certificate is trusted through config('webhooks.testing_http_options'), which the delivery service
 *   reads only in the testing environment.
 */

beforeEach(function (): void {
    $this->listeners = [];
    $this->tenant = complianceTenant();
});

afterEach(function (): void {
    foreach ($this->listeners as $listener) {
        $listener->stop();
    }
});

function webhookStartListener(object $test, array $responses): WebhookTestListener
{
    $listener = new WebhookTestListener($responses);
    $test->listeners[] = $listener;
    config(['webhooks.testing_http_options' => ['verify' => $listener->certificate]]);

    return $listener;
}

function webhookAllowLoopback(): void
{
    app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(new StaticHostResolver, ['127.0.0.1']));
}

test('a delivery reaches a real HTTPS listener with the exact signed body, the headers and the idempotency key', function (): void {
    $listener = webhookStartListener($this, [['status' => 200, 'body' => 'received']]);
    webhookAllowLoopback();
    $endpoint = webhookEndpointFor($this->tenant, ['invoicing.invoice.posted'], ['url' => $listener->url('/hook?source=ff')]);

    ingestWebhookEvent(webhookInvoicePostedEvent($this->tenant->id, 'FT-REAL-1'));

    $delivery = WebhookDelivery::withoutGlobalScopes()->sole();
    $requests = $listener->requests();

    expect($delivery->status)->toBe('succeeded')->and($delivery->last_response_code)->toBe(200)->and($delivery->last_duration_ms)->toBeInt()
        ->and($requests)->toHaveCount(1);

    $request = $requests[0];
    $headers = $request['headers'];
    $payload = json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR);

    expect($request['request_line'])->toBe('POST /hook?source=ff HTTP/1.1')
        ->and($headers['content-type'])->toBe('application/json')
        ->and($headers['user-agent'])->toBe(WebhookDeliveryService::UserAgent)
        ->and($headers['x-ff-webhook-id'])->toBe($delivery->idempotency_key)
        ->and($headers['x-ff-webhook-event'])->toBe('invoicing.invoice.posted')
        ->and($headers['x-ff-webhook-version'])->toBe('v1')
        ->and((int) $headers['content-length'])->toBe(strlen($request['body']))
        ->and(WebhookSigner::verify($headers['x-ff-webhook-signature'], $request['body'], $endpoint->secret))->toBeTrue()
        ->and(WebhookSigner::verify($headers['x-ff-webhook-signature'], $request['body'].' ', $endpoint->secret))->toBeFalse()
        ->and($payload['id'])->toBe($delivery->event_id)->and($payload['type'])->toBe('invoicing.invoice.posted')
        ->and($payload['api_version'])->toBe('v1')->and($payload['tenant_id'])->toBe($this->tenant->id)
        ->and($payload['data']['invoice_number'])->toBe('FT-REAL-1');
});

test('a 5xx answer is retried and the receiver sees the same idempotency key every time', function (): void {
    $listener = webhookStartListener($this, [['status' => 500, 'body' => 'boom'], ['status' => 503, 'body' => 'busy'], ['status' => 200, 'body' => 'ok']]);
    webhookAllowLoopback();
    $endpoint = webhookEndpointFor($this->tenant, ['invoicing.invoice.posted'], ['url' => $listener->url()]);

    ingestWebhookEvent(webhookInvoicePostedEvent($this->tenant->id));
    $delivery = WebhookDelivery::withoutGlobalScopes()->sole();

    expect($delivery->status)->toBe('retrying')->and($delivery->last_response_code)->toBe(500)->and($delivery->attempts)->toBe(1);

    $this->travel(2)->minutes();
    $delivery = webhookProcessDelivery($delivery);
    expect($delivery->status)->toBe('retrying')->and($delivery->last_response_code)->toBe(503)->and($delivery->attempts)->toBe(2);

    $this->travel(5)->minutes();
    $delivery = webhookProcessDelivery($delivery);
    expect($delivery->status)->toBe('succeeded')->and($delivery->attempts)->toBe(3);

    $requests = $listener->requests();
    expect($requests)->toHaveCount(3)
        ->and(collect($requests)->pluck('headers.x-ff-webhook-id')->unique()->all())->toBe([$delivery->idempotency_key]);

    foreach ($requests as $request) {
        expect(WebhookSigner::verify($request['headers']['x-ff-webhook-signature'], $request['body'], $endpoint->secret))->toBeTrue();
    }
});

test('a redirect answer is not followed: the listener never gets a second request', function (): void {
    // A relative Location points back at the same listener, so a followed redirect would show up in its log.
    $listener = webhookStartListener($this, [['status' => 307, 'body' => '', 'location' => '/followed']]);
    webhookAllowLoopback();
    webhookEndpointFor($this->tenant, ['invoicing.invoice.posted'], ['url' => $listener->url('/first')]);

    ingestWebhookEvent(webhookInvoicePostedEvent($this->tenant->id));

    $delivery = WebhookDelivery::withoutGlobalScopes()->sole();

    expect($delivery->status)->toBe('retrying')->and($delivery->last_response_code)->toBe(307)->and($delivery->last_error)->toContain('redirect')
        ->and(array_column($listener->requests(), 'request_line'))->toBe(['POST /first HTTP/1.1']);
});

test('without the allow-list, loopback is refused: nothing reaches the listener', function (): void {
    $listener = webhookStartListener($this, [['status' => 200]]);
    // The guard is the one of the application (testing environment): no allow-list.
    webhookEndpointFor($this->tenant, ['invoicing.invoice.posted'], ['url' => $listener->url()]);

    ingestWebhookEvent(webhookInvoicePostedEvent($this->tenant->id));

    $delivery = WebhookDelivery::withoutGlobalScopes()->sole();

    expect($delivery->status)->toBe('retrying')->and($delivery->last_error)->toStartWith('Blocked:')->and($delivery->last_response_code)->toBeNull()
        ->and($listener->requests())->toHaveCount(0);
});

test('the testing-only transport option is ignored outside the testing environment', function (): void {
    $listener = webhookStartListener($this, [['status' => 200]]);
    webhookAllowLoopback();
    webhookEndpointFor($this->tenant, ['invoicing.invoice.posted'], ['url' => $listener->url()]);
    app()->detectEnvironment(fn (): string => 'production');

    ingestWebhookEvent(webhookInvoicePostedEvent($this->tenant->id));

    $delivery = WebhookDelivery::withoutGlobalScopes()->sole();

    // The self-signed certificate is not trusted: the handshake fails, no request is sent, the delivery is retried later.
    expect($delivery->status)->toBe('retrying')->and($delivery->last_response_code)->toBeNull()->and($delivery->last_error)->not->toBeNull()
        ->and($listener->requests())->toHaveCount(0);
});
