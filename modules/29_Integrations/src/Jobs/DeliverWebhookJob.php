<?php

declare(strict_types=1);

namespace Modules\Integrations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\Webhooks\WebhookDeliveryService;

/**
 * Sends one attempt of a webhook delivery. It carries only the id (never a model, never a secret) and does not rely on
 * the queue's own retry: the delivery schedules its next attempt itself, with a delay that the database queue driver
 * honours, and the scheduled sweeper queues it again if a job is ever lost.
 */
final class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $deliveryId)
    {
        $this->afterCommit();
    }

    public function handle(WebhookDeliveryService $deliveries): void
    {
        $deliveries->process($this->deliveryId);
    }
}
