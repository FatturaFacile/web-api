<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Models\SalesReturnItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A return request: the delivery note or invoice the goods came with, the reason, and the lines returned (each naming
 * the line of the source document).
 */
class StoreSalesReturnRequest extends FormRequest
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
            'delivery_note_id' => ['required_without:invoice_id', 'nullable', 'uuid', $this->tenantOwns('delivery_notes')],
            'invoice_id' => ['required_without:delivery_note_id', 'nullable', 'uuid', $this->tenantOwns('invoices')],
            'warehouse_id' => ['nullable', 'uuid', $this->tenantOwns('warehouses')],
            'date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.delivery_note_item_id' => ['nullable', 'uuid', 'required_without:items.*.invoice_item_id'],
            'items.*.invoice_item_id' => ['nullable', 'uuid', 'required_without:items.*.delivery_note_item_id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'items.*.condition' => ['nullable', Rule::in(SalesReturnItem::Conditions)],
            'items.*.restock' => ['nullable', 'boolean'],
        ];
    }
}
