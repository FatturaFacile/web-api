<?php

declare(strict_types=1);

namespace Modules\Projects\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Inventory\Events\ProjectInventoryIssueEvent;
use Modules\Inventory\Events\ProjectInventoryReservationEvent;
use Modules\Inventory\Events\ProjectMaterialConsumptionEvent;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\DTOs\RecordJobCostDTO;
use Modules\Projects\Models\Project;

final readonly class InventoryIntegrationListener implements ShouldQueue
{
    public function __construct(
        private JobCostingAccumulationServiceInterface $costingService
    ) {}

    public function handleMaterialConsumption(ProjectMaterialConsumptionEvent $event): void
    {
        $project = Project::find($event->projectId);
        if (! $project) {
            return;
        }

        $this->costingService->recordCost(new RecordJobCostDTO(
            projectId: $event->projectId,
            costCategory: 'material',
            costSourceType: 'StockMovement',
            costSourceId: $event->getAggregateId(),
            quantity: $event->quantity,
            unitCost: $event->unitCost,
            totalCost: $event->totalCost,
            incurredDate: now()->toDateString(),
            taskId: $event->taskId,
            uom: 'PCE',
            isBillable: true
        ));
    }

    public function handleInventoryIssue(ProjectInventoryIssueEvent $event): void
    {
        $project = Project::find($event->projectId);
        if (! $project) {
            return;
        }

        $this->costingService->recordCost(new RecordJobCostDTO(
            projectId: $event->projectId,
            costCategory: 'material',
            costSourceType: 'StockMovement',
            costSourceId: $event->issueId,
            quantity: $event->quantity,
            unitCost: $event->unitCost,
            totalCost: $event->totalCost,
            incurredDate: now()->toDateString(),
            taskId: $event->taskId,
            uom: 'PCE',
            isBillable: true
        ));
    }

    public function handleInventoryReservation(ProjectInventoryReservationEvent $event): void
    {
        // Trace reservation note
        $project = Project::find($event->projectId);
        if ($project) {
            $project->notes()->create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'author_user_id' => $project->project_manager_user_id,
                'title' => 'Inventory Staging Reserved',
                'content' => "Stock item {$event->stockItemId} (Qty: {$event->quantity}) reserved under reservation #{$event->reservationId}",
            ]);
        }
    }
}
