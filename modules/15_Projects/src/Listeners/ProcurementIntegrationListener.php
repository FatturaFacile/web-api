<?php

declare(strict_types=1);

namespace Modules\Projects\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;
use Modules\Procurement\Events\PurchaseOrderApprovedEvent;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\DTOs\RecordJobCostDTO;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectFinancialSummary;

final readonly class ProcurementIntegrationListener implements ShouldQueue
{
    public function __construct(
        private JobCostingAccumulationServiceInterface $costingService
    ) {}

    public function handlePurchaseOrderApproved(PurchaseOrderApprovedEvent $event): void
    {
        // If PO has project association in payload or context
        $projectId = $event->projectId ?? null;
        if (! $projectId) {
            return;
        }

        $project = Project::find($projectId);
        if (! $project) {
            return;
        }

        // Increment committed PO cost on financial summary
        $summary = ProjectFinancialSummary::firstOrCreate(
            ['project_id' => $project->id],
            [
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'total_contract_revenue' => $project->contract_value,
            ]
        );

        $summary->increment('committed_po_cost', (float) $event->totalAmount);
    }

    public function handleGoodsReceived(GoodsReceivedEvent $event): void
    {
        $projectId = $event->projectId ?? null;
        if (! $projectId) {
            return;
        }

        $project = Project::find($projectId);
        if (! $project) {
            return;
        }

        $this->costingService->recordCost(new RecordJobCostDTO(
            projectId: $projectId,
            costCategory: 'supplier',
            costSourceType: 'PurchaseReceipt',
            costSourceId: $event->receiptId,
            quantity: 1.0,
            unitCost: (float) ($event->receivedAmount ?? 0.0),
            totalCost: (float) ($event->receivedAmount ?? 0.0),
            incurredDate: now()->toDateString(),
            uom: 'EA',
            isBillable: true
        ));
    }

    public function handlePurchaseLiability(PurchaseLiabilityReadyEvent $event): void
    {
        $projectId = $event->projectId ?? null;
        if (! $projectId) {
            return;
        }

        $project = Project::find($projectId);
        if (! $project) {
            return;
        }

        $this->costingService->recordCost(new RecordJobCostDTO(
            projectId: $projectId,
            costCategory: 'supplier',
            costSourceType: 'PurchaseLiability',
            costSourceId: $event->liabilityId,
            quantity: 1.0,
            unitCost: (float) $event->totalAmount,
            totalCost: (float) $event->totalAmount,
            incurredDate: now()->toDateString(),
            uom: 'EA',
            isBillable: true
        ));
    }
}
