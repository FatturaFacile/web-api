<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Subcontracting\Models\SubcontractingOrder;

/**
 * Production on the customer's material: good and scrapped units, the Mod 064 production order that ran, optional actual
 * consumption per component line.
 */
final class RecordActiveProductionRequest extends FormRequest
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
            'production_order_id' => ['nullable', 'uuid'],
            'components' => ['nullable', 'array', 'max:200'],
            'components.*.component_id' => ['required', 'uuid', 'distinct'],
            'components.*.consumed_qty' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'components.*.scrap_qty' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ];
    }
}
