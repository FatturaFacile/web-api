<?php

declare(strict_types=1);

namespace Modules\Integrations\Webhooks;

use App\Exceptions\UnsafeOutboundUrlException;
use App\Models\Tenant;
use App\Services\Security\OutboundUrlGuard;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Integrations\Jobs\DeliverWebhookJob;
use Modules\Integrations\Models\WebhookDelivery;
use Modules\Integrations\Models\WebhookDeliveryAttempt;
use Modules\Integrations\Models\WebhookEndpoint;
use Modules\Integrations\Webhooks\Exceptions\UnsafeWebhookUrl;
use RuntimeException;
use Throwable;

/**
 * Sends the deliveries and decides what happens after each attempt.
 *
 * Retries: a failed attempt (no answer, or an answer other than 2xx; a redirect is a failure because redirects are
 * never followed) schedules the next one with an exponential backoff, until config('webhooks.max_attempts'); then the
 * delivery is a dead letter, kept in the log and redeliverable by hand. Consecutive failed attempts of an endpoint
 * disable it (see WebhookEndpointManager::disable). Test pings are sent once, with no retries, and do not count.
 *
 * Several workers can hold a job for the same delivery (a postponed job, a job the sweeper queued again): an atomic
 * claim on the row lets exactly one of them send.
 */
final class WebhookDeliveryService
{
    public const string UserAgent = 'FatturaFacile-Webhooks/1.0';

    public function __construct(
        private readonly WebhookUrlGuard $guard,
        private readonly WebhookSigner $signer,
        private readonly WebhookEndpointManager $endpoints,
        private readonly TenantContext $tenantContext,
        private readonly WebhookPayloadBuilder $payloads,
        private readonly OutboundUrlGuard $outbound,
    ) {}

    /**
     * Sends one attempt of a delivery that is due, if this worker wins the claim.
     */
    public function process(string $deliveryId): void
    {
        $delivery = WebhookDelivery::withoutGlobalScopes()->find($deliveryId);

        if ($delivery === null || ! in_array($delivery->status, [WebhookDelivery::StatusPending, WebhookDelivery::StatusRetrying], true)) {
            return;
        }

        $endpoint = $this->endpointOf($delivery);

        if ($endpoint === null) {
            $this->markDead($delivery, 'The endpoint no longer exists.');

            return;
        }

        if (! $endpoint->isActive() && ! $delivery->is_test) {
            $this->markDead($delivery, 'The endpoint is disabled.');

            return;
        }

        if (! $delivery->is_test) {
            $limitKey = 'webhooks:tenant:'.$delivery->tenant_id;
            $limit = max(1, (int) config('webhooks.rate_limit_per_minute'));

            if (RateLimiter::tooManyAttempts($limitKey, $limit)) {
                DeliverWebhookJob::dispatch((string) $delivery->id)->delay(max(1, RateLimiter::availableIn($limitKey)) + random_int(0, 3));

                return;
            }
        }

        if (! $this->claim($delivery)) {
            return;
        }

        if (! $delivery->is_test) {
            RateLimiter::hit('webhooks:tenant:'.$delivery->tenant_id, 60);
        }

        $this->runAttempt($delivery->refresh(), $endpoint);
    }

    /**
     * Sends a test ping to the endpoint at once and returns its delivery with the outcome.
     */
    public function ping(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $eventId = (string) Str::uuid7();
        $version = (string) $endpoint->payload_version;

        $delivery = $this->tenantContext->runWithTenant(Tenant::findOrFail($endpoint->tenant_id), fn (): WebhookDelivery => WebhookDelivery::create([
            'tenant_id' => $endpoint->tenant_id,
            'webhook_endpoint_id' => $endpoint->id,
            'event_id' => $eventId,
            'event_name' => WebhookPayloadBuilder::PingEvent,
            'idempotency_key' => (string) Str::uuid7(),
            'status' => WebhookDelivery::StatusPending,
            'next_attempt_at' => now(),
            'payload' => $this->payloads->build($version, $eventId, WebhookPayloadBuilder::PingEvent, (string) $endpoint->tenant_id, now(), ['message' => 'This is a test event sent from the webhook settings.']),
            'payload_version' => $version,
            'is_test' => true,
        ]));

        $this->claim($delivery);
        $this->runAttempt($delivery->refresh(), $endpoint);

        return $delivery->refresh();
    }

    /**
     * Queues again the deliveries that are due but have no live job (a job lost with a worker, a queue that was
     * flushed). Run every minute by the scheduler. Returns how many were queued.
     */
    public function dispatchOverdue(int $limit = 500): int
    {
        $overdue = WebhookDelivery::withoutGlobalScopes()
            ->whereIn('status', [WebhookDelivery::StatusPending, WebhookDelivery::StatusRetrying])
            ->where('next_attempt_at', '<=', now()->subMinutes(2))
            ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subSeconds((int) config('webhooks.claim_timeout_seconds'))))
            ->orderBy('next_attempt_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($overdue as $id) {
            DeliverWebhookJob::dispatch((string) $id);
        }

        return $overdue->count();
    }

    /**
     * Seconds to wait after the given number of failed attempts, jitter excluded.
     */
    public function backoffSeconds(int $failedAttempts): int
    {
        $exponent = max(0, min($failedAttempts - 1, 30));

        return (int) min((int) config('webhooks.backoff_base_seconds') * (2 ** $exponent), (int) config('webhooks.backoff_max_seconds'));
    }

    private function endpointOf(WebhookDelivery $delivery): ?WebhookEndpoint
    {
        return WebhookEndpoint::withoutGlobalScopes()
            ->where('tenant_id', $delivery->tenant_id)
            ->whereKey($delivery->webhook_endpoint_id)
            ->first();
    }

    private function claim(WebhookDelivery $delivery): bool
    {
        return WebhookDelivery::withoutGlobalScopes()
            ->whereKey($delivery->id)
            ->whereIn('status', [WebhookDelivery::StatusPending, WebhookDelivery::StatusRetrying])
            ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subSeconds((int) config('webhooks.claim_timeout_seconds'))))
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update(['claimed_at' => now()]) === 1;
    }

    private function runAttempt(WebhookDelivery $delivery, WebhookEndpoint $endpoint): void
    {
        $result = $this->send($endpoint, $delivery);
        $attemptNumber = $delivery->attempts + 1;

        WebhookDeliveryAttempt::create([
            'tenant_id' => $delivery->tenant_id,
            'webhook_delivery_id' => $delivery->id,
            'attempt_number' => $attemptNumber,
            'succeeded' => $result['succeeded'],
            'response_code' => $result['code'],
            'duration_ms' => $result['duration_ms'],
            'error' => $result['error'],
            'response_excerpt' => $result['excerpt'],
            'created_at' => now(),
        ]);

        $common = [
            'attempts' => $attemptNumber,
            'last_response_code' => $result['code'],
            'last_duration_ms' => $result['duration_ms'],
            'last_error' => $result['error'],
            'claimed_at' => null,
        ];

        if ($result['succeeded']) {
            $delivery->forceFill([...$common, 'status' => WebhookDelivery::StatusSucceeded, 'delivered_at' => now(), 'next_attempt_at' => null])->save();

            if (! $delivery->is_test) {
                WebhookEndpoint::withoutGlobalScopes()->whereKey($endpoint->id)->update(['consecutive_failures' => 0, 'last_success_at' => now()]);
            }

            return;
        }

        $exhausted = $delivery->is_test || $attemptNumber >= max(1, (int) config('webhooks.max_attempts'));

        if ($exhausted) {
            $delivery->forceFill([...$common, 'status' => WebhookDelivery::StatusDead, 'next_attempt_at' => null])->save();
        } else {
            $backoff = $this->backoffSeconds($attemptNumber);
            $delay = $backoff + random_int(0, max(0, intdiv($backoff, 10)));

            $delivery->forceFill([...$common, 'status' => WebhookDelivery::StatusRetrying, 'next_attempt_at' => now()->addSeconds($delay)])->save();

            DeliverWebhookJob::dispatch((string) $delivery->id)->delay($delay);
        }

        if (! $delivery->is_test) {
            WebhookEndpoint::withoutGlobalScopes()->whereKey($endpoint->id)->increment('consecutive_failures', 1, ['last_failure_at' => now()]);
            $this->disableWhenFailingTooLong($endpoint->id);
        }
    }

    private function disableWhenFailingTooLong(string $endpointId): void
    {
        $endpoint = WebhookEndpoint::withoutGlobalScopes()->find($endpointId);
        $threshold = (int) config('webhooks.auto_disable_after_failures');

        if ($endpoint !== null && $endpoint->isActive() && $threshold > 0 && $endpoint->consecutive_failures >= $threshold) {
            $this->endpoints->disable($endpoint, "Disabled automatically after {$endpoint->consecutive_failures} consecutive failed deliveries.", true);
        }
    }

    private function markDead(WebhookDelivery $delivery, string $reason): void
    {
        WebhookDelivery::withoutGlobalScopes()->whereKey($delivery->id)->update([
            'status' => WebhookDelivery::StatusDead,
            'claimed_at' => null,
            'next_attempt_at' => null,
            'last_error' => $reason,
        ]);
    }

    /**
     * @return array{succeeded: bool, code: ?int, duration_ms: int, error: ?string, excerpt: ?string}
     */
    private function send(WebhookEndpoint $endpoint, WebhookDelivery $delivery): array
    {
        $started = hrtime(true);
        $elapsed = static fn (): int => (int) ((hrtime(true) - $started) / 1_000_000);

        try {
            $this->guard->assertSafe((string) $endpoint->url);
            $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $timestamp = time();

            $request = Http::withHeaders([
                'User-Agent' => self::UserAgent,
                'X-FF-Webhook-Id' => (string) $delivery->idempotency_key,
                'X-FF-Webhook-Event' => (string) $delivery->event_name,
                'X-FF-Webhook-Version' => (string) $delivery->payload_version,
                WebhookSigner::SignatureHeader => $this->signer->header($timestamp, $body, $endpoint->signingSecrets()),
            ])
                ->withBody($body, 'application/json')
                ->connectTimeout((int) config('webhooks.connect_timeout_seconds'))
                ->timeout((int) config('webhooks.timeout_seconds'))
                ->withOptions(array_merge([
                    'proxy' => '',
                    'on_headers' => static function ($response): void {
                        if ((int) $response->getHeaderLine('Content-Length') > 1_048_576) {
                            throw new RuntimeException('The response is larger than 1 MiB.');
                        }
                    },
                ], app()->environment('testing') ? (array) config('webhooks.testing_http_options', []) : []));

            // The guard checks the host again, pins the addresses it checked (no DNS rebinding) and refuses redirects.
            $response = $this->outbound->protect($request)->post((string) $endpoint->url);

            $code = $response->status();
            $succeeded = $response->successful();
            $excerpt = mb_strcut(mb_scrub($response->body()), 0, (int) config('webhooks.response_excerpt_bytes'));

            $error = match (true) {
                $succeeded => null,
                $code >= 300 && $code < 400 => "The endpoint answered with a redirect (HTTP {$code}); redirects are not followed.",
                default => "The endpoint answered HTTP {$code}.",
            };

            return ['succeeded' => $succeeded, 'code' => $code, 'duration_ms' => $elapsed(), 'error' => $error, 'excerpt' => $excerpt === '' ? null : $excerpt];
        } catch (UnsafeWebhookUrl|UnsafeOutboundUrlException $exception) {
            return ['succeeded' => false, 'code' => null, 'duration_ms' => $elapsed(), 'error' => 'Blocked: '.$exception->getMessage(), 'excerpt' => null];
        } catch (Throwable $exception) {
            return ['succeeded' => false, 'code' => null, 'duration_ms' => $elapsed(), 'error' => Str::limit(class_basename($exception).': '.$exception->getMessage(), 500), 'excerpt' => null];
        }
    }
}
