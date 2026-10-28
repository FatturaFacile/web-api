<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['sometimes', 'uuid'],
            'order_date' => ['sometimes', 'date'],
            'expected_delivery_date' => ['sometimes', 'date'],
            'po_number' => ['nullable', 'string', 'max:100'],
            'order_type' => ['sometimes', 'string', 'in:standard,blanket_release,subcontract'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'supplier_contract_id' => ['nullable', 'uuid'],
            'purchase_requisition_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'cost_center_id' => ['nullable', 'uuid'],
            'department_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
            'payment_terms_code' => ['nullable', 'string', 'max:50'],
            'incoterms' => ['nullable', 'string', 'max:10'],
            'shipping_address' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.id' => ['nullable', 'uuid'],
            'lines.*.description' => ['required_with:lines', 'string'],
            'lines.*.quantity_ordered' => ['required_with:lines', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required_with:lines', 'numeric', 'gte:0'],
            'lines.*.uom' => ['required_with:lines', 'string', 'max:20'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.supplier_catalog_id' => ['nullable', 'uuid'],
        ];
    }
}
