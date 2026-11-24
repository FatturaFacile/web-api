<?php

declare(strict_types=1);

namespace Modules\Workflow\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Services\TransactionalOutboxService;

final class DispatchOutboxOnWorkflowEventListener
{
    public function __construct(
        private readonly TransactionalOutboxService $outboxService
    ) {}

    public function handle(DomainEventInterface $event): void
    {
        $this->outboxService->record($event);
    }
}
