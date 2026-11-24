<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request for quotation to several suppliers, from an approved requisition or a list of items.
 */
final class StoreRfqRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'response_deadline' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'currency' => ['nullable', 'string', 'size:3'],
            'purchase_requisition_id' => ['nullable', 'uuid', $this->tenantOwns('purchase_requisitions')],
            'cost_center_id' => ['nullable', 'uuid', $this->tenantExists('cost_centers')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'supplier_ids' => ['required', 'array', 'min:1'],
            'supplier_ids.*' => ['required', 'uuid', $this->tenantOwns('suppliers')],
            'lines' => ['required_without:purchase_requisition_id', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.uom' => ['nullable', 'string', 'max:20'],
            'lines.*.internal_sku' => ['nullable', 'string', 'max:100'],
            'lines.*.required_by_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
