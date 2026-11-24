<?php

declare(strict_types=1);

namespace Modules\Projects\Listeners;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Modules\Inventory\Events\ProjectInventoryIssueEvent;
use Modules\Inventory\Events\ProjectInventoryReservationEvent;
use Modules\Inventory\Events\ProjectMaterialConsumptionEvent;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\DTOs\RecordJobCostDTO;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectTask;
use Modules\Projects\Support\Money;

/**
 * Books the material that Inventory issues or consumes for a project as actual material cost, at the cost the stock
 * valuation gave it. Each stock document is booked once (the issue id, or the event id of a consumption), and a task
 * that does not belong to the project is ignored rather than failing the booking. Reservations only leave a note.
 */
final readonly class InventoryIntegrationListener implements ShouldQueueAfterCommit
{
    public function __construct(
        private JobCostingAccumulationServiceInterface $costingService,
        private TenantContext $tenantContext,
    ) {}

    public function handleMaterialConsumption(ProjectMaterialConsumptionEvent $event): void
    {
        $this->forTenant($event->tenantId, $event->companyId, function () use ($event): void {
            $this->book($event->projectId, $event->taskId, $event->getEventId(), (string) $event->quantity, (string) $event->unitCost, (string) $event->totalCost, "Stock item {$event->stockItemId}");
        });
    }

    public function handleInventoryIssue(ProjectInventoryIssueEvent $event): void
    {
        $this->forTenant($event->tenantId, $event->companyId, function () use ($event): void {
            $total = Money::amount(Money::mul((string) $event->quantity, (string) $event->unitCost));
            $this->book($event->projectId, $event->taskId, $event->issueId, (string) $event->quantity, (string) $event->unitCost, $total, "Stock issue {$event->issueId}");
        });
    }

    public function handleInventoryReservation(ProjectInventoryReservationEvent $event): void
    {
        $this->forTenant($event->tenantId, $event->companyId, function () use ($event): void {
            $project = Project::query()->find($event->projectId);

            $project?->notes()->create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'author_user_id' => $project->project_manager_user_id,
                'title' => 'Inventory reserved',
                'content' => "Stock item {$event->stockItemId} (quantity {$event->quantity}) reserved under reservation {$event->reservationId}",
            ]);
        });
    }

    private function book(string $projectId, string $taskId, string $sourceId, string $quantity, string $unitCost, string $totalCost, string $description): void
    {
        $project = Project::query()->find($projectId);

        if (! $project instanceof Project || ! $project->isOpen()) {
            return;
        }

        $taskOfProject = ProjectTask::query()->whereKey($taskId)->where('project_id', $project->id)->exists();

        $this->costingService->recordCost(new RecordJobCostDTO(
            projectId: (string) $project->id,
            costCategory: 'material',
            costSourceType: 'StockMovement',
            costSourceId: $sourceId,
            quantity: Money::of($quantity),
            unitCost: Money::of($unitCost),
            totalCost: Money::amount($totalCost),
            incurredDate: now()->toDateString(),
            taskId: $taskOfProject ? $taskId : null,
            uom: 'PCE',
            isBillable: true,
            description: $description,
        ));
    }

    /**
     * Queued listeners run without the tenant of the request: the event names it.
     */
    private function forTenant(string $tenantId, string $companyId, Closure $callback): void
    {
        $tenant = Tenant::query()->find($tenantId !== '' ? $tenantId : $companyId);

        if ($tenant === null) {
            return;
        }

        $this->tenantContext->runWithTenant($tenant, $callback);
    }
}
