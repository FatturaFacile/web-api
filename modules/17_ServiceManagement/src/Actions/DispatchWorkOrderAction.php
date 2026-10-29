<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Actions;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\DB;
use Modules\ServiceManagement\Models\WorkOrder;

final readonly class DispatchWorkOrderAction
{
    public function __construct(
        private AuditLedgerInterface $auditLedger
    ) {}

    public function execute(string $workOrderId, ?string $leadTechnicianId = null, ?string $actorUserId = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrderId, $leadTechnicianId) {
            $workOrder = WorkOrder::findOrFail($workOrderId);

            $oldValues = [
                'status' => $workOrder->status,
                'lead_technician_id' => $workOrder->lead_technician_id,
            ];

            $workOrder->update([
                'status' => 'dispatched',
                'lead_technician_id' => $leadTechnicianId ?? $workOrder->lead_technician_id,
            ]);

            $this->auditLedger->record(
                'WORK_ORDER_DISPATCHED',
                $workOrder,
                $oldValues,
                ['technician_id' => $leadTechnicianId],
                'MODULE_17_ESM'
            );

            return $workOrder;
        });
    }
}
