<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Modules\Procurement\Models\PurchaseOrder;
use Modules\Projects\Models\Project;

/**
 * Passes the CUP and CIG codes of a public-sector project on to the documents that belong to it: the purchase orders of
 * the procurement module (stored on the order, so they travel with it) and, when an invoice is built, the FatturaPA
 * DatiContratto / DatiConvenzione block (see ProjectInvoiceReferenceBlock).
 *
 * Cancelled and closed orders keep the codes they were issued with.
 */
final readonly class ProjectReferenceCodePropagator
{
    private const array FrozenStatuses = ['cancelled', 'closed'];

    /**
     * Writes the codes of the project on all its open purchase orders.
     *
     * @return int purchase orders updated
     */
    public function propagateToPurchaseOrders(Project $project): int
    {
        return PurchaseOrder::query()
            ->where('project_id', $project->id)
            ->whereNotIn('status', self::FrozenStatuses)
            ->update(['cup_code' => $project->cup_code, 'cig_code' => $project->cig_code]);
    }

    /**
     * A purchase order that is opened on a project without codes of its own takes the codes of the project.
     */
    public function inheritOnPurchaseOrder(PurchaseOrder $order): void
    {
        if ($order->project_id === null || ($order->cup_code !== null && $order->cup_code !== '') || ($order->cig_code !== null && $order->cig_code !== '')) {
            return;
        }

        $project = Project::query()->whereKey($order->project_id)->first(['id', 'cup_code', 'cig_code']);

        if ($project === null) {
            return;
        }

        $order->cup_code = $project->cup_code;
        $order->cig_code = $project->cig_code;
    }
}
