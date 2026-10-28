<?php

declare(strict_types=1);

namespace Modules\Inventory\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Inventory\Events\StockTransferredEvent;

final readonly class AuditInventoryMovementListener implements ShouldQueue
{
    public function __construct(
        private ?AuditLedgerServiceInterface $auditLedger = null
    ) {}

    public function handle(StockTransferredEvent $event): void
    {
        if ($this->auditLedger === null) {
            return;
        }

        $this->auditLedger->recordEvent(
            action: 'stock_transferred',
            aggregateType: $event->getAggregateType(),
            aggregateId: $event->getAggregateId(),
            tenantId: $event->getTenantId(),
            companyId: $event->getCompanyId(),
            payload: $event->toPayload()
        );
    }
}
