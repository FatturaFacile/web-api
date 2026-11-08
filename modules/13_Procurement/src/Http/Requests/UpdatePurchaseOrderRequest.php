<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Changes to a draft (or rejected) purchase order; the supplier, the number and the agreement cannot change. Given
 * lines replace the current ones.
 */
final class UpdatePurchaseOrderRequest extends FormRequest
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
            'supplier_id' => ['prohibited'],
            'po_number' => ['prohibited'],
            'supplier_contract_id' => ['prohibited'],
            'order_date' => ['sometimes', 'date_format:Y-m-d'],
            'expected_delivery_date' => ['sometimes', 'date_format:Y-m-d'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'exchange_rate' => ['sometimes', 'decimal:0,6', 'gt:0'],
            'project_id' => ['nullable', 'uuid', $this->tenantOwns('projects')],
            'cost_center_id' => ['nullable', 'uuid', $this->tenantExists('cost_centers')],
            'department_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
            'payment_terms_code' => ['nullable', 'string', 'max:50'],
            'incoterms' => ['nullable', 'string', 'max:10'],
            'shipping_address' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...StorePurchaseOrderRequest::lineRules('sometimes'),
        ];
    }
}
