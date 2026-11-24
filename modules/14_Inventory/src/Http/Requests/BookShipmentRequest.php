<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

final class BookShipmentRequest extends FormRequest
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
            'shipping_carrier_id' => ['required', 'uuid', $this->tenantExists('shipping_carriers')],
            'stock_packing_list_id' => ['nullable', 'uuid', $this->tenantExists('stock_packing_lists')],
            'delivery_note_id' => ['nullable', 'uuid', $this->tenantExists('delivery_notes')],
            'packages_count' => ['nullable', 'integer', 'min:1'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'recipient' => ['required', 'array'],
            'recipient.name' => ['required', 'string', 'max:150'],
            'recipient.address' => ['required', 'string', 'max:255'],
            'recipient.city' => ['required', 'string', 'max:100'],
            'recipient.postal_code' => ['required', 'string', 'max:20'],
            'recipient.country' => ['required', 'string', 'size:2'],
            'recipient.phone' => ['nullable', 'string', 'max:50'],
            'recipient.email' => ['nullable', 'email'],
        ];
    }
}
