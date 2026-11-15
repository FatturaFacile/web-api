<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Requests;

use Illuminate\Contracts\Validation\Validator;

/**
 * Payload to create or change a tariff band.
 */
final class TariffBandRequest extends TransportationFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'origin_zone' => ['nullable', 'string', 'max:30'],
            'destination_zone' => ['nullable', 'string', 'max:30'],
            'weight_from_kg' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'weight_to_kg' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'distance_from_km' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'distance_to_km' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'fixed_amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'price_per_kg' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'price_per_km' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
        ]);

        return $this->isMethod('POST') ? [
            'tariff_table_id' => ['required', 'uuid', $this->ownRow('tms_tariff_tables')],
            ...$rules,
        ] : $rules;
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

            foreach ([['weight_from_kg', 'weight_to_kg'], ['distance_from_km', 'distance_to_km']] as [$fromField, $toField]) {
                $from = $this->valueOf($fromField, 'tms_tariff_bands') ?? '0';
                $to = $this->valueOf($toField, 'tms_tariff_bands');

                if ($to !== null && bccomp((string) $to, (string) $from, 3) <= 0) {
                    $validator->errors()->add($toField, __('transportation_errors.tariff.range_order'));
                }
            }
        });
    }
}
