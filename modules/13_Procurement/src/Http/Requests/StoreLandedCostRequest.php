<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Procurement\Services\LandedCostService;

/**
 * A landed cost to allocate on goods receipts.
 */
final class StoreLandedCostRequest extends FormRequest
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
            'cost_type' => ['required', 'string', 'in:'.implode(',', LandedCostService::CostTypes)],
            'allocation_method' => ['required', 'string', 'in:'.implode(',', LandedCostService::Methods)],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'cost_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:255'],
            'passive_invoice_id' => ['nullable', 'uuid', $this->tenantOwns('passive_invoices')],
            'supplier_id' => ['nullable', 'uuid', $this->tenantOwns('suppliers')],
            'purchase_receipt_ids' => ['required', 'array', 'min:1'],
            'purchase_receipt_ids.*' => ['required', 'uuid', $this->tenantOwns('purchase_receipts')],
        ];
    }
}
