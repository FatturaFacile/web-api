<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Subcontracting\Models\SubcontractingOrder;

/**
 * Dispatch of material to a vendor, return of unused material and receipt of customer material: an optional list of quantities per
 * component line (without it the whole open quantity moves), the document date and the transport notes.
 */
final class QuantityLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = SubcontractingOrder::query()->whereKey($this->route('id'))->first();

        return $order instanceof SubcontractingOrder && ($this->user()?->can($this->ability(), $order) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['nullable', 'array', 'max:200'],
            'lines.*.component_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'date' => ['nullable', 'date'],
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'customer_ddt_number' => ['nullable', 'string', 'max:60'],
            'customer_ddt_date' => ['nullable', 'date'],
            'customer_ddt_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** dispatch for the movements that send material out, receive for the ones that bring it in. */
    private function ability(): string
    {
        return str_contains((string) $this->route()?->getName(), 'dispatch') ? 'dispatch' : 'receive';
    }
}
