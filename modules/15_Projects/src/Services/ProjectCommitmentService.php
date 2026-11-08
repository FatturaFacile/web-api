<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Projects\Models\Project;
use Modules\Projects\Support\Money;

/**
 * Commitments of a project: the value of the purchase orders charged to it that is ordered but not yet received.
 * Received goods become actual supplier cost through the goods receipt, so commitment plus actual never counts the
 * same purchase twice. Commitments belong to the supplier category of the budget.
 */
final class ProjectCommitmentService
{
    /** Purchase order statuses whose open quantity is still a commitment. */
    public const array OpenStatuses = ['approved', 'sent', 'confirmed', 'partially_received'];

    /**
     * Open (not received, not cancelled) value of the purchase orders of the project, net of VAT.
     */
    public function openCommitments(Project $project): string
    {
        $orderIds = PurchaseOrder::query()
            ->where('project_id', $project->id)
            ->whereIn('status', self::OpenStatuses)
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            return Money::amount(0);
        }

        $open = Money::of(0);

        PurchaseOrderLine::query()
            ->whereIn('purchase_order_id', $orderIds)
            ->where('is_closed', false)
            ->get(['quantity_ordered', 'quantity_received', 'quantity_cancelled', 'unit_price'])
            ->each(function (PurchaseOrderLine $line) use (&$open): void {
                $remaining = Money::sub(Money::sub($line->getAttribute('quantity_ordered'), $line->getAttribute('quantity_received')), $line->getAttribute('quantity_cancelled'));

                if (Money::isPositive($remaining)) {
                    $open = Money::add($open, Money::mul($remaining, $line->getAttribute('unit_price')));
                }
            });

        return Money::amount($open);
    }
}
