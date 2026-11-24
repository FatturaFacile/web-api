<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePurchaseRequisitionRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:255'],
            'required_by_date' => ['sometimes', 'date'],
            'urgency' => ['sometimes', 'string', 'in:low,medium,high,emergency'],
            'project_id' => ['nullable', 'uuid', $this->tenantOwns('projects')],
            'cost_center_id' => ['nullable', 'uuid', $this->tenantExists('cost_centers')],
            'department_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.id' => ['nullable', 'uuid'],
            'lines.*.description' => ['required_with:lines', 'string'],
            'lines.*.quantity_requested' => ['required_with:lines', 'numeric', 'gt:0'],
            'lines.*.uom' => ['required_with:lines', 'string', 'max:20'],
            'lines.*.estimated_unit_price' => ['required_with:lines', 'numeric', 'gte:0'],
            'lines.*.supplier_catalog_id' => ['nullable', 'uuid', $this->tenantOwns('supplier_catalogs')],
            'lines.*.suggested_supplier_id' => ['nullable', 'uuid', $this->tenantOwns('suppliers')],
        ];
    }
}
