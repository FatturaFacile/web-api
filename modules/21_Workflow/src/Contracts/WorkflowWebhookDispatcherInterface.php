<?php

declare(strict_types=1);

namespace Modules\Workflow\Contracts;

use Modules\Workflow\Models\WorkflowDeliveryAttempt;

interface WorkflowWebhookDispatcherInterface
{
    /**
     * Enqueues an outbound workflow event for external subscribers.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $tenantId, string $eventName, array $payload): void;

    /**
     * Executes delivery attempt for a staged event.
     */
    public function deliver(WorkflowDeliveryAttempt $attempt): bool;
}
