<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Models\SalesReturnItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Receipt of an authorised return: optionally the warehouse and, per line, the quantity received, its condition and
 * whether it goes back into stock (every line is received in full when no line is given).
 */
class ReceiveSalesReturnRequest extends FormRequest
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
            'warehouse_id' => ['nullable', 'uuid', $this->tenantOwns('warehouses')],
            'items' => ['sometimes', 'array', 'max:500'],
            'items.*.id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity_received' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
            'items.*.condition' => ['nullable', Rule::in(SalesReturnItem::Conditions)],
            'items.*.restock' => ['nullable', 'boolean'],
        ];
    }
}
