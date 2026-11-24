<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Workflow\Contracts\WorkflowWebhookDispatcherInterface;
use Modules\Workflow\Models\WorkflowDeliveryAttempt;
use Modules\Workflow\Models\WorkflowOutboundEvent;
use Modules\Workflow\Models\WorkflowWebhookSubscription;
use Throwable;

final class WorkflowWebhookDispatcherService implements WorkflowWebhookDispatcherInterface
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_PROCESSING = 'processing';

    public const string STATUS_SUCCESSFUL = 'successful';

    public const string STATUS_FAILED = 'failed';

    /**
     * The delivery gave up after its last attempt: it waits for a person, nothing retries it any more.
     */
    public const string STATUS_DEAD_LETTER = 'dead_letter';

    public const int MAX_ATTEMPTS = 5;

    /**
     * Delay before the second attempt; every further attempt waits four times longer, up to one day.
     */
    public const int BASE_BACKOFF_SECONDS = 60;

    public const int MAX_BACKOFF_SECONDS = 86400;

    /**
     * A delivery still marked processing after this long belongs to a worker that died: it is taken over again.
     */
    public const int STALE_PROCESSING_MINUTES = 10;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $tenantId, string $eventName, array $payload): void
    {
        $subscriptions = WorkflowWebhookSubscription::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $matchingSubscriptions = $subscriptions->filter(function ($sub) use ($eventName) {
            $events = $sub->subscribed_events ?? [];

            return in_array($eventName, $events, true) || in_array('*', $events, true);
        });

        if ($matchingSubscriptions->isEmpty()) {
            return;
        }

        $outboundEvent = WorkflowOutboundEvent::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'event_name' => $eventName,
            'event_payload' => $payload,
            'created_at' => now(),
        ]);

        foreach ($matchingSubscriptions as $subscription) {
            WorkflowDeliveryAttempt::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'subscription_id' => $subscription->id,
                'outbound_event_id' => $outboundEvent->id,
                'attempt_number' => 1,
                'status' => self::STATUS_PENDING,
                'next_retry_at' => now(),
            ]);
        }
    }

    /**
     * Takes the deliveries that are due, so that no other worker sends them at the same time: the rows are locked with
     * SKIP LOCKED and marked processing before the transaction ends. A delivery left processing by a dead worker
     * counts as a failed attempt and is taken over again, or dead-lettered when it has no attempts left.
     *
     * @return Collection<int, WorkflowDeliveryAttempt>
     */
    public function claimDueAttempts(int $limit = 50): Collection
    {
        return DB::transaction(function () use ($limit): Collection {
            $now = now();
            $staleBefore = $now->copy()->subMinutes(self::STALE_PROCESSING_MINUTES);

            $attempts = WorkflowDeliveryAttempt::query()
                ->where(function ($query) use ($now, $staleBefore): void {
                    $query->where(function ($due) use ($now): void {
                        $due->where('status', self::STATUS_PENDING)
                            ->where(fn ($retry) => $retry->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', $now));
                    })->orWhere(function ($stale) use ($staleBefore): void {
                        $stale->where('status', self::STATUS_PROCESSING)
                            ->where('updated_at', '<=', $staleBefore);
                    });
                })
                ->orderBy('created_at')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            $claimed = new Collection;

            foreach ($attempts as $attempt) {
                if ($attempt->status === self::STATUS_PROCESSING) {
                    if ($attempt->attempt_number >= self::MAX_ATTEMPTS) {
                        $this->deadLetter($attempt, 'Delivery interrupted: the worker stopped while sending it.');

                        continue;
                    }

                    $attempt->attempt_number++;
                }

                $attempt->status = self::STATUS_PROCESSING;
                $attempt->updated_at = $now;
                $attempt->save();
                $claimed->push($attempt);
            }

            return $claimed->load(['subscription', 'outboundEvent']);
        });
    }

    public function deliver(WorkflowDeliveryAttempt $attempt): bool
    {
        $subscription = $attempt->subscription;
        $event = $attempt->outboundEvent;

        if ($subscription === null || $event === null) {
            $attempt->update(['status' => self::STATUS_FAILED, 'next_retry_at' => null]);

            return false;
        }

        $payloadJson = json_encode($event->event_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hmac = hash_hmac('sha256', $payloadJson, $subscription->secret_key);

        $startTime = microtime(true);
        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-FatturaFacile-Signature' => "sha256={$hmac}",
                    'X-FatturaFacile-Event' => $event->event_name,
                    'Idempotency-Key' => $attempt->id,
                ])
                ->post($subscription->target_url, $event->event_payload);

            $latency = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $attempt->update([
                    'http_status' => $response->status(),
                    'response_body' => substr($response->body(), 0, 1000),
                    'latency_ms' => $latency,
                    'status' => self::STATUS_SUCCESSFUL,
                    'next_retry_at' => null,
                ]);

                return true;
            }

            $this->recordFailure($attempt, $response->status(), $response->body(), $latency);

            return false;
        } catch (Throwable $e) {
            $latency = (int) ((microtime(true) - $startTime) * 1000);
            $this->recordFailure($attempt, 500, $e->getMessage(), $latency);

            return false;
        }
    }

    /**
     * Delay before the attempt that follows the given failed one: 1, 4, 16, 64 minutes and so on, at most one day.
     */
    public function backoffSeconds(int $failedAttemptNumber): int
    {
        $exponent = max(0, $failedAttemptNumber - 1);

        return (int) min(self::MAX_BACKOFF_SECONDS, self::BASE_BACKOFF_SECONDS * (4 ** $exponent));
    }

    /**
     * A failed attempt schedules the next one with exponential backoff; the last one dead-letters the delivery.
     */
    private function recordFailure(WorkflowDeliveryAttempt $attempt, int $httpStatus, string $responseBody, int $latency): void
    {
        $attempt->fill([
            'http_status' => $httpStatus,
            'response_body' => substr($responseBody, 0, 1000),
            'latency_ms' => $latency,
        ]);

        if ($attempt->attempt_number >= self::MAX_ATTEMPTS) {
            $this->deadLetter($attempt, $responseBody);

            return;
        }

        $attempt->fill([
            'status' => self::STATUS_PENDING,
            'next_retry_at' => $this->calculateNextRetry($attempt->attempt_number),
            'attempt_number' => $attempt->attempt_number + 1,
        ])->save();
    }

    private function deadLetter(WorkflowDeliveryAttempt $attempt, string $reason): void
    {
        $attempt->fill([
            'status' => self::STATUS_DEAD_LETTER,
            'next_retry_at' => null,
        ])->save();

        Log::warning('Workflow webhook delivery moved to the dead letter state.', [
            'delivery_attempt_id' => $attempt->id,
            'subscription_id' => $attempt->subscription_id,
            'outbound_event_id' => $attempt->outbound_event_id,
            'tenant_id' => $attempt->tenant_id,
            'attempts' => $attempt->attempt_number,
            'reason' => substr($reason, 0, 500),
        ]);
    }

    private function calculateNextRetry(int $failedAttemptNumber): Carbon
    {
        return now()->addSeconds($this->backoffSeconds($failedAttemptNumber));
    }
}
