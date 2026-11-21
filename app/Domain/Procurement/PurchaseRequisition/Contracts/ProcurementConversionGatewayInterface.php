<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Contracts;

use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use Modules\Procurement\Models\PurchaseOrder;

/**
 * Downstream conversion contract bridging Mod 044 Purchase Requisitions to Mod 045 (RFQ) and Mod 046 (PO).
 */
interface ProcurementConversionGatewayInterface
{
    /**
     * Creates a draft Mod 045 RFQ from prepared requisition line items.
     *
     * @param  list<array{requisition_id: string, requisition_number: string, requisition_line_id: string, supplier_catalog_id: ?string, internal_sku: ?string, description: string, quantity: string, unit_of_measure: string, estimated_unit_price: string, cost_center_id: ?string, project_id: ?string, requested_delivery_date: ?string}>  $preparedLines
     * @param  array{title?: ?string, description?: ?string, currency: string, submission_deadline?: ?string, expected_delivery_date?: ?string, notes?: ?string, supplier_ids?: list<string>, sealed_bidding?: bool}  $options
     * @return array{document: RequestForQuotation, document_number: string, target_line_ids: list<string>}
     */
    public function convertToRfq(
        string $tenantId,
        ?string $actorUserId,
        array $preparedLines,
        array $options,
    ): array;

    /**
     * Creates a draft Mod 046 Purchase Order from prepared requisition line items.
     *
     * @param  list<array{requisition_id: string, requisition_number: string, requisition_line_id: string, supplier_catalog_id: ?string, internal_sku: ?string, description: string, quantity: string, unit_of_measure: string, estimated_unit_price: string, cost_center_id: ?string, project_id: ?string, requested_delivery_date: ?string}>  $preparedLines
     * @param  array{currency: string, expected_delivery_date?: ?string, notes?: ?string, cost_center_id?: ?string, project_id?: ?string, department_id?: ?string, single_requisition_id?: ?string}  $options
     * @return array{document: PurchaseOrder, document_number: string, target_line_ids: list<string>}
     */
    public function convertToPurchaseOrder(
        string $tenantId,
        ?string $actorUserId,
        string $supplierId,
        array $preparedLines,
        array $options,
    ): array;
}
