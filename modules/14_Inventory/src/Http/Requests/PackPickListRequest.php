<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

final class PackPickListRequest extends FormRequest
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
            'delivery_note_id' => ['nullable', 'uuid', $this->tenantExists('delivery_notes')],
            'packages' => ['nullable', 'array', 'min:1'],
            'packages.*.package_type' => ['required', 'string', 'in:box,carton,pallet'],
            'packages.*.tare_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'packages.*.items' => ['required', 'array', 'min:1'],
            'packages.*.items.*.stock_item_id' => ['required', 'uuid', $this->tenantExists('stock_items')],
            'packages.*.items.*.lot_id' => ['nullable', 'uuid', $this->tenantExists('stock_lots')],
            'packages.*.items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
