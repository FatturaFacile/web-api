<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Transportation\Models\TmsCarrier;

/**
 * Payload to create or change a carrier.
 */
final class CarrierRequest extends TransportationFormRequest
{
    /**
     * The code is compared and stored in upper case.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'code' => ['required', 'string', 'max:30', $this->uniqueIn('tms_carriers', 'code')],
            'name' => ['required', 'string', 'max:160'],
            'mode' => ['sometimes', Rule::in(TmsCarrier::Modes)],
            'service_level' => ['nullable', 'string', 'max:60'],
            'currency' => ['sometimes', 'string', 'max:3', 'size:3'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return $rules;
    }
}
