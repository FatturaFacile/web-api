<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of a non-conformance raised by hand.
 */
final class StoreNonConformanceRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'production_order_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_production_orders')],
            'mfg_item_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_items')],
            'severity' => ['required', 'string', 'in:minor,major,critical'],
            'defect_type' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:5000'],
            'quantity' => ['nullable', ...self::Quantity],
        ];
    }
}
