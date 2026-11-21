<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Handlers;

use App\Application\Procurement\Rfq\Queries\GetRfqDetailQuery;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;

/**
 * Loads the complete RequestForQuotation aggregate including lines, attachments, invitations, bids, and buyer.
 */
final class GetRfqDetailHandler
{
    public function handle(GetRfqDetailQuery $query): RequestForQuotation
    {
        return RequestForQuotation::query()
            ->with([
                'lines.awardedSupplier',
                'lines.generatedPurchaseOrder',
                'attachments',
                'invitations.supplier',
                'bids.lines',
                'bids.supplier',
                'buyer',
                'awardedBy',
                'sourceRequisitionLinks.requisition',
            ])
            ->withCount([
                'lines as lines_count',
                'invitations as invited_count',
            ])
            ->findOrFail($query->rfqId);
    }
}
