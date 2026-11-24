<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of a demand forecast entry.
 */
final class StoreForecastRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mfg_item_id' => ['required', 'uuid', $this->tenantOwns('mfg_items')],
            'forecast_date' => ['required', 'date'],
            'quantity' => ['required', ...self::PositiveQuantity],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
