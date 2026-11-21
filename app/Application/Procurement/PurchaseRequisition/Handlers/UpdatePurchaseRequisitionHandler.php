<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Handlers;

use App\Application\Procurement\PurchaseRequisition\Commands\UpdatePurchaseRequisitionCommand;
use App\Application\Procurement\PurchaseRequisition\Support\RequisitionLineWriteSupport;
use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionStatus;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use App\Domain\Procurement\PurchaseRequisition\Services\RequisitionTotalsDomainService;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Services\ProcurementAuditLedgerService;

/**
 * Handles updates to an editable Purchase Requisition (draft or budget_exceeded_blocked only).
 */
final readonly class UpdatePurchaseRequisitionHandler
{
    public function __construct(
        private RequisitionLineWriteSupport $lineWriter,
        private RequisitionTotalsDomainService $totalsService,
        private ProcurementAuditLedgerService $auditLedger,
    ) {}

    public function handle(UpdatePurchaseRequisitionCommand $command): PurchaseRequisition
    {
        /** @var PurchaseRequisition $requisition */
        $requisition = DB::transaction(function () use ($command): PurchaseRequisition {
            $requisition = PurchaseRequisition::query()
                ->where('tenant_id', $command->tenantId)
                ->whereKey($command->requisitionId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $requisition->statusEnum()->isEditable()) {
                throw new ProcurementRuleViolation(
                    __('purchase_requisition.errors.cannot_update_status', ['status' => $requisition->status]),
                    'status'
                );
            }

            $before = [
                'status' => $requisition->status,
                'total_estimated_amount' => (string) $requisition->total_estimated_amount,
                'cost_center_id' => $requisition->cost_center_id,
            ];

            $updates = [];

            if ($command->hasDepartmentId) {
                $updates['department_id'] = $command->departmentId;
            }
            if ($command->hasCostCenterId) {
                $updates['cost_center_id'] = $command->costCenterId;
            }
            if ($command->hasProjectId) {
                $updates['project_id'] = $command->projectId;
            }
            if ($command->urgency !== null) {
                $updates['urgency'] = $command->urgency;
            }
            if ($command->hasRequestedDeliveryDate) {
                $updates['requested_delivery_date'] = $command->requestedDeliveryDate;
                $updates['required_by_date'] = $command->requestedDeliveryDate;
            }
            if ($command->hasEconomicJustification) {
                $updates['economic_justification'] = $command->economicJustification;
            }
            if ($command->currency !== null) {
                $updates['currency'] = strtoupper(trim($command->currency));
            }
            if ($command->hasTitle) {
                $updates['title'] = $command->title ?? '';
            }
            if ($command->hasNotes) {
                $updates['notes'] = $command->notes;
            }

            if ($requisition->statusEnum() === RequisitionStatus::BudgetExceededBlocked) {
                $updates['status'] = RequisitionStatus::Draft->value;
                $updates['budget_check_snapshot'] = null;
                $updates['budget_warning_acknowledged'] = false;
            }

            if ($updates !== []) {
                $requisition->update($updates);
            }

            if ($command->lines !== null) {
                $totalAmount = $this->lineWriter->replaceLines($requisition, $command->lines);
                $requisition->update(['total_estimated_amount' => $totalAmount]);
            } else {
                $requisition->load('lines');
                $lineSummary = $this->totalsService->summarizeDraftPayload(
                    $requisition->cost_center_id !== null ? (string) $requisition->cost_center_id : null,
                    $requisition->lines->map(static fn ($line): array => [
                        'quantity' => $line->effectiveQuantity(),
                        'estimated_unit_price' => $line->estimated_unit_price,
                        'cost_center_id' => $line->cost_center_id,
                    ])->all()
                );
                $requisition->update(['total_estimated_amount' => $lineSummary['total_estimated_amount']]);
            }

            $this->auditLedger->record('requisition.updated', $requisition, $before, [
                'status' => $requisition->status,
                'total_estimated_amount' => (string) $requisition->total_estimated_amount,
                'updated_by' => $command->actorUserId,
            ]);

            return $requisition;
        });

        return $requisition->fresh(['lines', 'requester', 'project', 'approvalLogs.approver', 'documentLinks']) ?? $requisition;
    }
}
