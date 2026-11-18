<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Listeners;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Modules\ServiceManagement\Events\RmaCompletedEvent;
use Modules\ServiceManagement\Events\RmaWarrantyCostChargedEvent;
use Modules\ServiceManagement\Events\ServiceCostReadyEvent;

/**
 * Bridges depot repair (RMA) completion and warranty cost charging to accounting and inventory consumers.
 */
final class RmaAccountingAndInventoryListener
{
    public function handleWarrantyCostCharged(RmaWarrantyCostChargedEvent $event): void
    {
        if ($event->totalCost <= 0.0) {
            return;
        }

        Event::dispatch(new ServiceCostReadyEvent(
            workOrderId: $event->rmaOrderId,
            totalCost: $event->totalCost,
            costCenterId: $event->costBucketCode !== '' ? $event->costBucketCode : null,
            projectId: null,
            completionDate: now()->toDateString(),
            tenantId: $event->tenantId,
            companyId: $event->companyId,
        ));
    }

    public function handleRmaCompleted(RmaCompletedEvent $event): void
    {
        if ($event->consumedParts === []) {
            return;
        }

        Log::info('RMA completed with consumed spare parts', [
            'rma_order_id' => $event->rmaOrderId,
            'rma_number' => $event->rmaNumber,
            'repair_outcome' => $event->repairOutcome,
            'warranty_status' => $event->warrantyStatus,
            'consumed_parts_count' => count($event->consumedParts),
            'tenant_id' => $event->tenantId,
        ]);
    }
}
