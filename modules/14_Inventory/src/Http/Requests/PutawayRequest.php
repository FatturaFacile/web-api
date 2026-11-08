<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

/**
 * A putaway suggestion (warehouse, item, quantity) or a putaway move (source bin, item, quantity, optional lot and
 * destination bin).
 */
final class PutawayRequest extends FormRequest
{
    use ValidatesTenantRecords;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $suggesting = $this->routeIs('inventory.putaway.suggest');

        return [
            'warehouse_id' => [$suggesting ? 'required' : 'prohibited', 'uuid', $this->tenantExists('warehouses')],
            'source_bin_id' => [$suggesting ? 'prohibited' : 'required', 'uuid', $this->tenantExists('warehouse_bins')],
            'destination_bin_id' => [$suggesting ? 'prohibited' : 'nullable', 'uuid', $this->tenantExists('warehouse_bins')],
            'stock_item_id' => ['required', 'uuid', $this->tenantExists('stock_items')],
            'lot_id' => ['nullable', 'uuid', $this->tenantExists('stock_lots')],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
