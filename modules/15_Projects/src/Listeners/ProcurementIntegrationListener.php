<?php

declare(strict_types=1);

namespace Modules\Projects\Listeners;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\DTOs\RecordJobCostDTO;
use Modules\Projects\Models\Project;
use Modules\Projects\Support\Money;

/**
 * Turns goods received on a purchase order charged to a project into actual supplier cost of that project, valued at
 * the order price of the accepted quantities. The open part of the order stays a commitment
 * (ProjectCommitmentService); the supplier invoice is not booked again, so receipt and invoice never count twice.
 */
final readonly class ProcurementIntegrationListener implements ShouldQueueAfterCommit
{
    public function __construct(
        private JobCostingAccumulationServiceInterface $costingService,
        private TenantContext $tenantContext,
    ) {}

    public function handleGoodsReceived(GoodsReceivedEvent $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId);

        if ($tenant === null) {
            return;
        }

        $this->tenantContext->runWithTenant($tenant, function () use ($event): void {
            $order = PurchaseOrder::query()->find($event->purchaseOrderId);
            $projectId = $order?->getAttribute('project_id');

            if ($projectId === null || ! Project::query()->whereKey($projectId)->exists()) {
                return;
            }

            $prices = PurchaseOrderLine::query()
                ->whereIn('id', array_column($event->receivedLines, 'purchase_order_line_id'))
                ->pluck('unit_price', 'id');
            $value = Money::of(0);

            foreach ($event->receivedLines as $line) {
                $value = Money::add($value, Money::mul((string) $line['quantity_accepted'], (string) ($prices[$line['purchase_order_line_id']] ?? '0')));
            }

            if (! Money::isPositive($value)) {
                return;
            }

            $this->costingService->recordCost(new RecordJobCostDTO(
                projectId: (string) $projectId,
                costCategory: 'supplier',
                costSourceType: 'PurchaseReceipt',
                costSourceId: $event->receiptId,
                quantity: '1',
                unitCost: Money::amount($value),
                totalCost: Money::amount($value),
                incurredDate: now()->toDateString(),
                uom: 'EA',
                isBillable: true,
                description: "Goods receipt {$event->receiptNumber}",
            ));
        });
    }
}
