<?php

declare(strict_types=1);

namespace Modules\Procurement\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Procurement\Events\SupplierApprovedEvent;
use Modules\Procurement\Models\Supplier;

final readonly class UpdateAvlOnSupplierApprovedListener implements ShouldQueue
{
    public function __construct(
        private ?AuditLedgerInterface $auditLedger = null
    ) {}

    public function handle(SupplierApprovedEvent $event): void
    {
        $supplier = Supplier::find($event->supplierId);
        if ($supplier === null) {
            return;
        }

        $beforeState = $supplier->only(['status', 'is_approved_vendor', 'rating']);

        $supplier->update([
            'status' => 'active',
            'is_approved_vendor' => true,
            'rating' => $supplier->rating ?? 'A',
            'is_locked' => false,
        ]);

        $afterState = $supplier->only(['status', 'is_approved_vendor', 'rating']);

        $this->auditLedger?->record(
            action: 'SUPPLIER_APPROVED_AVL_UPDATED',
            model: $supplier,
            payloadBefore: $beforeState,
            payloadAfter: $afterState,
            moduleCode: 'PROCUREMENT'
        );
    }
}
