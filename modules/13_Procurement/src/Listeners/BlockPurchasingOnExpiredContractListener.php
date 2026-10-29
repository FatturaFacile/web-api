<?php

declare(strict_types=1);

namespace Modules\Procurement\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Procurement\Events\SupplierContractExpiredEvent;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierContract;

final readonly class BlockPurchasingOnExpiredContractListener implements ShouldQueue
{
    public function __construct(
        private ?AuditLedgerInterface $auditLedger = null
    ) {}

    public function handle(SupplierContractExpiredEvent $event): void
    {
        // Check if supplier has any other active contracts
        $hasActive = SupplierContract::where('supplier_id', $event->supplierId)
            ->where('id', '!=', $event->contractId)
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->exists();

        if (! $hasActive) {
            $supplier = Supplier::find($event->supplierId);
            if ($supplier !== null) {
                $before = ['is_approved_vendor' => $supplier->is_approved_vendor];
                $supplier->update(['is_approved_vendor' => false]);

                $this->auditLedger?->record(
                    action: 'SUPPLIER_PURCHASING_BLOCKED_CONTRACT_EXPIRED',
                    model: $supplier,
                    payloadBefore: $before,
                    payloadAfter: ['is_approved_vendor' => false, 'expired_contract_id' => $event->contractId],
                    moduleCode: 'PROCUREMENT'
                );
            }
        }
    }
}
