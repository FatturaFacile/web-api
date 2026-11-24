<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

/**
 * Opening a ticket (first weighing) or closing it (second weighing): the weight comes typed, from a device reading, or
 * from the tare stored on the vehicle card.
 */
final class WeighbridgeTicketRequest extends FormRequest
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
        $opening = $this->route('id') === null;

        return [
            'weighbridge_vehicle_id' => [$opening ? 'required' : 'nullable', 'uuid', $this->tenantExists('weighbridge_vehicles')],
            'direction' => [$opening ? 'required' : 'nullable', 'string', 'in:inbound,outbound'],
            'contact_id' => ['nullable', 'uuid', $this->tenantExists('contacts')],
            'stock_item_id' => ['nullable', 'uuid', $this->tenantExists('stock_items')],
            'weight_kg' => ['nullable', 'numeric', 'gt:0'],
            'reading_id' => ['nullable', 'uuid', $this->tenantExists('weighbridge_readings')],
            'use_stored_tare' => ['nullable', 'boolean'],
        ];
    }
}
