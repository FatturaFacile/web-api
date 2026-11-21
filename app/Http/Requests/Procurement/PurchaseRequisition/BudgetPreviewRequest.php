<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement\PurchaseRequisition;

use App\Http\Requests\Procurement\PurchaseRequisition\Concerns\HasPurchaseRequisitionValidationMessages;
use App\Rules\TenantCostCenterExists;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates read-only real-time budget preview requests for Purchase Requisitions.
 */
final class BudgetPreviewRequest extends FormRequest
{
    use HasPurchaseRequisitionValidationMessages;

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

        return [
            'cost_center_id' => ['nullable', 'uuid', $costCenterRule],
            'currency' => ['nullable', 'string', 'in:CHF,EUR'],
            'fiscal_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'exclude_requisition_id' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.estimated_unit_price' => ['required', 'numeric', 'gte:0'],
            'lines.*.cost_center_id' => ['nullable', 'uuid', $costCenterRule],
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
