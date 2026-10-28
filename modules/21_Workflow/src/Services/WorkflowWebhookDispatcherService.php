<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Workflow\Contracts\WorkflowWebhookDispatcherInterface;
use Modules\Workflow\Models\WorkflowDeliveryAttempt;
use Modules\Workflow\Models\WorkflowOutboundEvent;
use Modules\Workflow\Models\WorkflowWebhookSubscription;
use Throwable;

final class WorkflowWebhookDispatcherService implements WorkflowWebhookDispatcherInterface
{
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
                'status' => 'pending',
                'next_retry_at' => now(),
            ]);
        }
    }

    public function deliver(WorkflowDeliveryAttempt $attempt): bool
    {
        $subscription = $attempt->subscription;
        $event = $attempt->outboundEvent;

        if ($subscription === null || $event === null) {
            $attempt->update(['status' => 'failed']);

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
            $success = $response->successful();

            $attempt->update([
                'http_status' => $response->status(),
                'response_body' => substr($response->body(), 0, 1000),
                'latency_ms' => $latency,
                'status' => $success ? 'successful' : 'pending',
                'next_retry_at' => $success ? null : $this->calculateNextRetry($attempt->attempt_number),
            ]);

            return $success;
        } catch (Throwable $e) {
            $latency = (int) ((microtime(true) - $startTime) * 1000);
            $attempt->update([
                'http_status' => 500,
                'response_body' => substr($e->getMessage(), 0, 1000),
                'latency_ms' => $latency,
                'status' => $attempt->attempt_number >= 5 ? 'failed' : 'pending',
                'next_retry_at' => $attempt->attempt_number >= 5 ? null : $this->calculateNextRetry($attempt->attempt_number),
            ]);

            return false;
        }
    }

    private function calculateNextRetry(int $attemptNumber): Carbon
    {
        $delaysMinutes = [1, 5, 30, 120, 1440];
        $minutes = $delaysMinutes[min($attemptNumber - 1, 4)] ?? 60;

        return now()->addMinutes($minutes);
    }
}
