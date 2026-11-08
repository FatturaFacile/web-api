<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of an output receipt: quantity, lot number or serial numbers for tracked items, warehouse and date.
 */
final class ReceiveOutputRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', ...self::PositiveQuantity],
            'lot_number' => ['nullable', 'string', 'max:100'],
            'serial_numbers' => ['nullable', 'array', 'max:1000'],
            'serial_numbers.*' => ['required', 'string', 'max:100', 'distinct'],
            'warehouse_id' => ['nullable', 'uuid', $this->tenantExists('warehouses')],
            'posting_date' => ['nullable', 'date'],
        ];
    }
}
