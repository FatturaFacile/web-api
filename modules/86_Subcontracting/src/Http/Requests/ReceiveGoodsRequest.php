<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Subcontracting\Models\SubcontractingOrder;

/**
 * Receipt (or preview of the receipt) of processed goods: the good and scrapped units, the vendor DDT reference, the warehouse
 * the goods enter and, to override the BOM backflush, the actual consumption and scrap per component line.
 */
final class ReceiveGoodsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = SubcontractingOrder::query()->whereKey($this->route('id'))->first();

        return $order instanceof SubcontractingOrder && ($this->user()?->can('receive', $order) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'good_quantity' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'scrap_quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'date' => ['nullable', 'date'],
            'vendor_ddt_number' => ['nullable', 'string', 'max:60'],
            'vendor_ddt_date' => ['nullable', 'date'],
            'inbound_document_type' => ['nullable', 'string', 'in:delivery_note,purchase_receipt'],
            'inbound_document_id' => ['nullable', 'uuid'],
            'output_warehouse_id' => ['nullable', 'uuid'],
            'components' => ['nullable', 'array', 'max:200'],
            'components.*.component_id' => ['required', 'uuid', 'distinct'],
            'components.*.consumed_qty' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'components.*.scrap_qty' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
