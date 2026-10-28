<?php

declare(strict_types=1);

namespace Modules\Invoicing\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Services\TransactionalOutboxService;
use Illuminate\Contracts\Queue\ShouldQueue;

final readonly class DispatchOutboxOnInvoicingEventListener implements ShouldQueue
{
    public function __construct(
        private TransactionalOutboxService $outboxService
    ) {}

    public function handle(DomainEventInterface $event): void
    {
        $this->outboxService->record($event);
    }
}
