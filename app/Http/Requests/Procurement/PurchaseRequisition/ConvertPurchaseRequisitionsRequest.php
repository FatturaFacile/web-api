<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement\PurchaseRequisition;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates grouping and/or partial splitting of approved Purchase Requisition lines into Mod 045 RFQ or Mod 046 PO.
 */
final class ConvertPurchaseRequisitionsRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target_type' => ['required', 'string', 'in:rfq,po'],
            'supplier_id' => ['required_if:target_type,po', 'nullable', 'uuid', $this->tenantOwns('suppliers')],
            'supplier_ids' => ['nullable', 'array'],
            'supplier_ids.*' => ['uuid', $this->tenantOwns('suppliers')],
            'title' => ['nullable', 'string', 'max:255'],
            'submission_deadline' => ['nullable', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.requisition_line_id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_type.in' => __('purchase_requisition.validation.invalid_target_type'),
            'supplier_id.required_if' => __('purchase_requisition.validation.supplier_required_for_po'),
            'items.required' => __('purchase_requisition.validation.items_required'),
            'items.min' => __('purchase_requisition.validation.items_required'),
            'items.*.quantity.gt' => __('purchase_requisition.validation.quantity_positive'),
        ];
    }
}
