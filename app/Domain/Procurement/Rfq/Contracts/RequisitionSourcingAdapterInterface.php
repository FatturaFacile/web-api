<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\Contracts;

use App\Domain\Procurement\Rfq\Models\RequestForQuotation;

/**
 * Contract bridging Mod 045 RFQs to Mod 044 Purchase Requisition lines.
 */
interface RequisitionSourcingAdapterInterface
{
    /**
     * Loads open / approved requisition lines with remaining unquoted quantities.
     *
     * @param  list<string>  $requisitionIds
     * @param  list<string>|null  $lineIds
     * @return list<array{id: string, purchase_requisition_id: string, requisition_number: string, currency: string, cost_center_id: ?string, supplier_catalog_id: ?string, internal_sku: ?string, description: string, quantity_requested: string, quantity_quoted: string, remaining_quantity: string, uom: string, estimated_unit_price: string, required_by_date: ?string}>
     */
    public function getQuotableLines(string $tenantId, array $requisitionIds, ?array $lineIds = null): array;

    /**
     * Validates and locks requested quantities on source Mod 044 PR lines when an RFQ is created/updated.
     *
     * @param  array<string, string>  $lineQuantities  Map of purchase_requisition_line_id => quantity to lock
     */
    public function lockLinesForRfq(string $tenantId, array $lineQuantities): void;

    /**
     * Releases locked quantities on source Mod 044 PR lines when an RFQ is cancelled or lines removed in draft.
     *
     * @param  array<string, string>  $lineQuantities  Map of purchase_requisition_line_id => quantity to release
     */
    public function releaseLinesFromRfq(string $tenantId, array $lineQuantities, ?string $excludingRfqId = null): void;

    /**
     * Marks linked Mod 044 PR lines as ordered upon RFQ award and transitions parent requisitions when fully ordered.
     */
    public function markRfqLinesOrdered(RequestForQuotation $rfq): void;
}
