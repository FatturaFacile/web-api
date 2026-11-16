<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Modules\Transportation\Models\TmsTariffTable;

/**
 * Payload to create or change a tariff table.
 */
final class TariffTableRequest extends TransportationFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'name' => ['required', 'string', 'max:160'],
            'currency' => ['sometimes', 'string', 'max:3', 'size:3'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d'],
            'rate_basis' => ['sometimes', Rule::in(TmsTariffTable::RateBases)],
            'fuel_surcharge_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'minimum_charge' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->isMethod('POST') ? [
            'carrier_id' => ['required', 'uuid', $this->ownRow('tms_carriers')],
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

            $from = $this->valueOf('valid_from', 'tms_tariff_tables');
            $to = $this->valueOf('valid_to', 'tms_tariff_tables');

            if ($from !== null && $to !== null && (string) $to < substr((string) $from, 0, 10)) {
                $validator->errors()->add('valid_to', __('transportation_errors.tariff.valid_to_before_from'));
            }
        });
    }
}
