<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StorePurchaseOrderRequest extends FormRequest
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
            'supplier_id' => ['required', 'uuid'],
            'order_date' => ['required', 'date'],
            'expected_delivery_date' => ['required', 'date', 'after_or_equal:order_date'],
            'po_number' => ['nullable', 'string', 'max:100'],
            'order_type' => ['nullable', 'string', 'in:standard,blanket_release,subcontract'],
            'currency' => ['nullable', 'string', 'size:3'],
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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.quantity_ordered' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'gte:0'],
            'lines.*.uom' => ['required', 'string', 'max:20'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.supplier_catalog_id' => ['nullable', 'uuid'],
        ];
    }
}
