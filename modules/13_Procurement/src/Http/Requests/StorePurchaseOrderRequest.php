<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new purchase order. The number comes from the gapless series of the company; a line without a price takes the
 * agreed price of its framework agreement line or the valid supplier price list price.
 */
final class StorePurchaseOrderRequest extends FormRequest
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
            'supplier_id' => ['required', 'uuid', $this->tenantOwns('suppliers')],
            'order_date' => ['required', 'date_format:Y-m-d'],
            'expected_delivery_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'po_number' => ['prohibited'],
            'order_type' => ['nullable', 'string', 'in:standard,blanket_release,subcontract'],
            'currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            'supplier_contract_id' => ['nullable', 'uuid', $this->tenantOwns('supplier_contracts')],
            'purchase_requisition_id' => ['nullable', 'uuid', $this->tenantOwns('purchase_requisitions')],
            'project_id' => ['nullable', 'uuid', $this->tenantOwns('projects')],
            'cost_center_id' => ['nullable', 'uuid', $this->tenantExists('cost_centers')],
            'department_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
            'payment_terms_code' => ['nullable', 'string', 'max:50'],
            'incoterms' => ['nullable', 'string', 'max:10'],
            'shipping_address' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...self::lineRules('required'),
        ];
    }

    /**
     * Rules of the order lines, shared with the update of a draft.
     *
     * @return array<string, list<string>>
     */
    public static function lineRules(string $presence): array
    {
        return [
            'lines' => [$presence, 'array', 'min:1'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity_ordered' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'decimal:0,4', 'min:0'],
            'lines.*.uom' => ['nullable', 'string', 'max:20'],
            'lines.*.tax_rate' => ['nullable', 'decimal:0,2', 'min:0', 'max:100'],
            'lines.*.supplier_catalog_id' => ['nullable', 'uuid'],
            'lines.*.supplier_contract_line_id' => ['nullable', 'uuid'],
            'lines.*.unit_weight' => ['nullable', 'decimal:0,4', 'min:0'],
            'lines.*.expected_delivery_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
