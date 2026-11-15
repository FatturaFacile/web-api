<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Modules\Transportation\Models\TmsTransportOrder;

/**
 * Payload to create or change a transport order.
 */
final class TransportOrderRequest extends TransportationFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'reference' => ['nullable', 'string', 'max:100'],
            'origin_name' => ['required', 'string', 'max:160'],
            'origin_location' => ['required', 'string', 'max:255'],
            'origin_city' => ['nullable', 'string', 'max:100'],
            'origin_zone' => ['nullable', 'string', 'max:30'],
            'destination_name' => ['required', 'string', 'max:160'],
            'destination_location' => ['required', 'string', 'max:255'],
            'destination_city' => ['nullable', 'string', 'max:100'],
            'destination_zone' => ['nullable', 'string', 'max:30'],
            'pickup_from' => ['nullable', 'date'],
            'pickup_to' => ['nullable', 'date'],
            'delivery_from' => ['nullable', 'date'],
            'delivery_to' => ['nullable', 'date'],
            'weight_kg' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'volume_cbm' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'pallets' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'distance_km' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'goods_description' => ['nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'max:3', 'size:3'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return $rules;
    }

    /**
     * Cross-field rules.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ([['pickup_from', 'pickup_to'], ['delivery_from', 'delivery_to']] as [$fromField, $toField]) {
                $from = $this->valueOf($fromField, 'tms_transport_orders');
                $to = $this->valueOf($toField, 'tms_transport_orders');

                if ($from !== null && $to !== null && \Illuminate\Support\Carbon::parse((string) $to)->lessThan(\Illuminate\Support\Carbon::parse((string) $from))) {
                    $validator->errors()->add($toField, __('transportation_errors.order.window_order'));
                }
            }
        });
    }
}
