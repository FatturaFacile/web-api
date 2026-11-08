<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A billing plan of a sales order of the active company: each item a percentage of the order net or an amount.
 */
final class StoreBillingPlanRequest extends FormRequest
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
            'sales_order_id' => ['required', 'uuid', $this->tenantOwns('sales_orders')],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.name' => ['required', 'string', 'max:150'],
            'items.*.percentage' => ['nullable', 'required_without:items.*.amount', 'numeric', 'gt:0', 'max:100'],
            'items.*.amount' => ['nullable', 'required_without:items.*.percentage', 'numeric', 'decimal:0,2', 'gt:0'],
            'items.*.target_date' => ['required', 'date'],
            'items.*.milestone_id' => ['nullable', 'uuid'],
        ];
    }
}
