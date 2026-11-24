<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement\PurchaseRequisition\Concerns;

/**
 * Shared localized validation error messages for Purchase Requisition FormRequests.
 */
trait HasPurchaseRequisitionValidationMessages
{
    /**
     * @return array<string, string>
     */
    protected function requisitionValidationMessages(): array
    {
        return [
            'currency.in' => __('purchase_requisition.validation.currency_supported'),
            'urgency.in' => __('purchase_requisition.validation.urgency_invalid'),
            'requested_delivery_date.date' => __('purchase_requisition.validation.delivery_date_invalid'),
            'project_id.exists' => __('purchase_requisition.validation.project_not_found'),
            'lines.required' => __('purchase_requisition.validation.lines_required'),
            'lines.min' => __('purchase_requisition.validation.lines_required'),
            'lines.*.item_type.in' => __('purchase_requisition.validation.item_type_invalid'),
            'lines.*.catalog_item_id.required_if' => __('purchase_requisition.validation.catalog_item_required'),
            'lines.*.description.required_if' => __('purchase_requisition.validation.description_required'),
            'lines.*.description.required_without' => __('purchase_requisition.validation.description_required'),
            'lines.*.quantity.gt' => __('purchase_requisition.validation.quantity_positive'),
            'lines.*.project_id.exists' => __('purchase_requisition.validation.project_not_found'),
        ];
    }
}
