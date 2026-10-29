<?php

declare(strict_types=1);

namespace Modules\Projects\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Services\TransactionalOutboxService;
use Illuminate\Contracts\Queue\ShouldQueue;

final readonly class DispatchOutboxOnProjectEventListener implements ShouldQueue
{
    public function __construct(
        private TransactionalOutboxService $outboxService
    ) {}

    public function handle(DomainEventInterface $event): void
    {
        $this->outboxService->record($event);
    }
}
