<?php

declare(strict_types=1);

namespace Modules\Inventory\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Modules\Inventory\Events\StockTransferredEvent;

final readonly class AuditInventoryMovementListener implements ShouldQueueAfterCommit
{
    public function __construct(
        private ?AuditLedgerInterface $auditLedger = null
    ) {}

    public function handle(StockTransferredEvent $event): void
    {
        if ($this->auditLedger === null) {
            return;
        }

        $this->auditLedger->recordPayload([
            'tenant_id' => $event->getTenantId(),
            'company_id' => $event->getCompanyId() !== '' ? $event->getCompanyId() : $event->getTenantId(),
            'module_code' => 'INVENTORY',
            'model_class' => $event->getAggregateType(),
            'model_id' => $event->getAggregateId(),
            'action' => 'STOCK_TRANSFERRED',
            'payload_after' => $event->toPayload(),
        ]);
    }
}
