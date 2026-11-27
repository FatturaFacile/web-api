<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Distribution\Http\Requests\Concerns\ScopesToTenant;

/**
 * Unsold goods returned from a consignment warehouse.
 */
final class ReturnConsignmentRequest extends FormRequest
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
            'warehouse_id' => ['required', 'uuid', $this->tenantExists('warehouses')],
            'destination_warehouse_id' => ['required', 'uuid', 'different:warehouse_id', $this->tenantExists('warehouses')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.stock_item_id' => ['required', 'uuid', $this->tenantExists('stock_items')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
