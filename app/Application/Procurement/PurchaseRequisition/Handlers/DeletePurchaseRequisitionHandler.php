<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Handlers;

use App\Application\Procurement\PurchaseRequisition\Commands\DeletePurchaseRequisitionCommand;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisitionLine;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Services\ProcurementAuditLedgerService;

/**
 * Handles soft deletion of draft Purchase Requisitions.
 */
final readonly class DeletePurchaseRequisitionHandler
{
    public function __construct(
        private ProcurementAuditLedgerService $auditLedger,
    ) {}

    public function handle(DeletePurchaseRequisitionCommand $command): void
    {
        DB::transaction(function () use ($command): void {
            $requisition = PurchaseRequisition::query()
                ->where('tenant_id', $command->tenantId)
                ->whereKey($command->requisitionId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $requisition->statusEnum()->isDeletable()) {
                throw new ProcurementRuleViolation(
                    __('purchase_requisition.errors.cannot_delete_non_draft'),
                    'status'
                );
            }

            $this->auditLedger->record('requisition.deleted', $requisition, [
                'requisition_number' => $requisition->requisition_number,
                'status' => $requisition->status,
                'deleted_by_actor' => $command->actorUserId,
            ]);

            $requisition->lines()->get()->each(static function (PurchaseRequisitionLine $line): void {
                $line->delete();
            });

            $requisition->delete();
        });
    }
}
