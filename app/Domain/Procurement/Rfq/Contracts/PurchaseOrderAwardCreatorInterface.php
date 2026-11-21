<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\Contracts;

use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqLine;
use App\Domain\Procurement\Rfq\Models\SupplierBid;
use App\Domain\Procurement\Rfq\Models\SupplierBidLine;
use Modules\Procurement\Models\PurchaseOrder;

/**
 * Contract bridging Mod 045 RFQ awards to Mod 046 Purchase Order generation.
 */
interface PurchaseOrderAwardCreatorInterface
{
    /**
     * Creates a Purchase Order for one winning supplier and its awarded lines.
     *
     * @param  list<array{rfq_line: RfqLine, bid: SupplierBid, bid_line: SupplierBidLine}>  $awardedItems
     */
    public function execute(
        RequestForQuotation $rfq,
        string $supplierId,
        array $awardedItems,
        ?string $actorUserId,
        ?string $expectedDeliveryDate = null,
        ?string $notes = null,
    ): PurchaseOrder;
}
