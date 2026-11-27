<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Distribution\Http\Requests\Concerns\ScopesToTenant;

/**
 * A draft transfer between two warehouses.
 */
final class StoreTransferRequest extends FormRequest
{
    use ScopesToTenant;

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
            'source_warehouse_id' => ['required', 'uuid', $this->tenantExists('warehouses')],
            'destination_warehouse_id' => ['required', 'uuid', 'different:source_warehouse_id', $this->tenantExists('warehouses')],
            'requested_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'auto_approve' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.stock_item_id' => ['required', 'uuid', $this->tenantExists('stock_items')],
            'lines.*.lot_id' => ['nullable', 'uuid', $this->tenantExists('stock_lots')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
