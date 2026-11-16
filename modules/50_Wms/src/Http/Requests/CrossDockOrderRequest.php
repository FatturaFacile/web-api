<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Requests;

/**
 * Payload of a cross-dock order with its lines (one per stock item); a PUT only validates the fields it sends and the
 * lines it sends replace the existing ones.
 */
final class CrossDockOrderRequest extends WmsFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'inbound_reference' => ['required', 'string', 'max:100'],
            'outbound_reference' => ['required', 'string', 'max:100'],
            'inbound_door_id' => ['nullable', 'uuid', $this->ownRow('wms_dock_doors')],
            'outbound_door_id' => ['nullable', 'uuid', $this->ownRow('wms_dock_doors')],
            'planned_inbound_at' => ['nullable', 'date'],
            'planned_outbound_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $lines = [
            'lines' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'array', 'min:1', 'max:200'],
            'lines.*.stock_item_id' => ['required', 'uuid', $this->ownRow('stock_items')],
            'lines.*.inbound_quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'lines.*.outbound_quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ];

        return $this->isMethod('POST')
            ? ['warehouse_id' => ['required', 'uuid', $this->ownRow('warehouses')], ...$rules, ...$lines]
            : [...$rules, ...$lines];
    }
}
