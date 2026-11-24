<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Handlers;

use App\Application\Procurement\PurchaseRequisition\Commands\CreatePurchaseRequisitionCommand;
use App\Application\Procurement\PurchaseRequisition\Support\RequisitionLineWriteSupport;
use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionStatus;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Services\ProcurementAuditLedgerService;
use Modules\Procurement\Services\ProcurementNumberingService;

/**
 * Handles creation of a Mod 044 Purchase Requisition in draft status.
 */
final readonly class CreatePurchaseRequisitionHandler
{
    public function __construct(
        private ProcurementNumberingService $numbering,
        private RequisitionLineWriteSupport $lineWriter,
        private ProcurementAuditLedgerService $auditLedger,
    ) {}

    public function handle(CreatePurchaseRequisitionCommand $command): PurchaseRequisition
    {
        /** @var PurchaseRequisition $requisition */
        $requisition = DB::transaction(function () use ($command): PurchaseRequisition {
            $requisitionNumber = $this->numbering->next(ProcurementNumberingService::Requisition, $command->tenantId);

            $requisition = PurchaseRequisition::query()->create([
                'tenant_id' => $command->tenantId,
                'requisition_number' => $requisitionNumber,
                'requester_user_id' => $command->requesterUserId,
                'requestor_user_id' => $command->requesterUserId,
                'department_id' => $command->departmentId,
                'cost_center_id' => $command->costCenterId,
                'project_id' => $command->projectId,
                'urgency' => $command->urgency,
                'requested_delivery_date' => $command->requestedDeliveryDate,
                'required_by_date' => $command->requestedDeliveryDate,
                'economic_justification' => $command->economicJustification,
                'currency' => strtoupper(trim($command->currency)),
                'total_estimated_amount' => '0.00',
                'status' => RequisitionStatus::Draft->value,
                'title' => $command->title ?? '',
                'notes' => $command->notes,
                'budget_warning_acknowledged' => false,
                'is_locked' => false,
            ]);

            $totalAmount = $this->lineWriter->replaceLines($requisition, $command->lines);
            $requisition->update(['total_estimated_amount' => $totalAmount]);

            $this->auditLedger->record('requisition.created', $requisition, null, [
                'requisition_number' => $requisition->requisition_number,
                'currency' => $requisition->currency,
                'total_estimated_amount' => $totalAmount,
            ]);

            return $requisition;
        });

        return $requisition->fresh(['lines', 'requester', 'project', 'approvalLogs.approver', 'documentLinks']) ?? $requisition;
    }
}
