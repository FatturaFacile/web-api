<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

final class GeneratePickListRequest extends FormRequest
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
        return [
            'warehouse_id' => ['required', 'uuid', $this->tenantExists('warehouses')],
            'assigned_picker_user_id' => ['nullable', 'uuid'],
            'origin_document_type' => ['nullable', 'string', 'max:255'],
            'origin_document_id' => ['nullable', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.stock_item_id' => ['required', 'uuid', $this->tenantExists('stock_items')],
            'items.*.quantity' => ['required_without:items.*.reservation_id', 'nullable', 'numeric', 'min:0.0001'],
            'items.*.lot_id' => ['nullable', 'uuid', $this->tenantExists('stock_lots')],
            'items.*.reservation_id' => ['nullable', 'uuid', $this->tenantExists('stock_reservations')],
        ];
    }
}
