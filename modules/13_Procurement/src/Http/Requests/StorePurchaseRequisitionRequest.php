<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class StorePurchaseRequisitionRequest extends FormRequest
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
            'required_by_date' => ['required', 'date', 'after_or_equal:today'],
            'urgency' => ['required', 'string', 'in:low,medium,high,emergency'],
            'project_id' => ['nullable', 'uuid', $this->tenantOwns('projects')],
            'cost_center_id' => ['nullable', 'uuid', $this->tenantExists('cost_centers')],
            'department_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.quantity_requested' => ['required', 'numeric', 'gt:0'],
            'lines.*.uom' => ['required', 'string', 'max:20'],
            'lines.*.estimated_unit_price' => ['required', 'numeric', 'gte:0'],
            'lines.*.supplier_catalog_id' => ['nullable', 'uuid', $this->tenantOwns('supplier_catalogs')],
            'lines.*.suggested_supplier_id' => ['nullable', 'uuid', $this->tenantOwns('suppliers')],
        ];
    }
}
