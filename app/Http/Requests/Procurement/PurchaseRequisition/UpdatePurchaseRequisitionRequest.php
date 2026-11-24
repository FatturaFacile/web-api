<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement\PurchaseRequisition;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Http\Requests\Procurement\PurchaseRequisition\Concerns\HasPurchaseRequisitionValidationMessages;
use App\Rules\TenantCostCenterExists;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates updates to an editable Mod 044 Purchase Requisition.
 */
final class UpdatePurchaseRequisitionRequest extends FormRequest
{
    use HasPurchaseRequisitionValidationMessages;
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
        $costCenterRule = new TenantCostCenterExists;
        $projectRule = $this->tenantOwns('projects');

        return [
            'department_id' => ['sometimes', 'nullable', 'uuid'],
            'cost_center_id' => ['sometimes', 'nullable', 'uuid', $costCenterRule],
            'project_id' => ['sometimes', 'nullable', 'uuid', $projectRule],
            'urgency' => ['sometimes', 'string', 'in:low,normal,high,critical'],
            'requested_delivery_date' => ['sometimes', 'nullable', 'date'],
            'economic_justification' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'currency' => ['sometimes', 'string', 'in:CHF,EUR'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.item_type' => ['nullable', 'string', 'in:catalog_item,free_text'],
            'lines.*.catalog_item_id' => ['required_if:lines.*.item_type,catalog_item', 'nullable', 'uuid'],
            'lines.*.description' => ['required_if:lines.*.item_type,free_text', 'required_without:lines.*.catalog_item_id', 'nullable', 'string', 'max:1000'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_of_measure' => ['nullable', 'string', 'max:32'],
            'lines.*.estimated_unit_price' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.cost_center_id' => ['nullable', 'uuid', $costCenterRule],
            'lines.*.project_id' => ['nullable', 'uuid', $projectRule],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->requisitionValidationMessages();
    }
}
