<?php

declare(strict_types=1);

namespace Enterprise\EventBus\Middleware;

use Closure;
use Enterprise\EventBus\Services\TransactionalInboxService;

final class IdempotentMessageMiddleware
{
    public function __construct(
        private readonly TransactionalInboxService $inboxService
    ) {}

    /**
     * Handle an incoming queued job or message idempotently.
     */
    public function handle(object $job, Closure $next): mixed
    {
        if (! method_exists($job, 'getMessageId')) {
            return $next($job);
        }

        $messageId = (string) $job->getMessageId();

        if ($this->inboxService->hasBeenProcessed($messageId)) {
            // Message already processed: skip execution silently
            return null;
        }

        $eventType = method_exists($job, 'getEventType') ? (string) $job->getEventType() : $job::class;
        $tenantId = method_exists($job, 'getTenantId') ? (string) $job->getTenantId() : '00000000-0000-0000-0000-000000000000';
        $payload = method_exists($job, 'getPayload') ? (array) $job->getPayload() : [];

        return $this->inboxService->executeIdempotent(
            messageId: $messageId,
            eventType: $eventType,
            tenantId: $tenantId,
            payload: $payload,
            handler: fn () => $next($job)
        );
    }
}
