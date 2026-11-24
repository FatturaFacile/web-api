<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Requests;

/**
 * One subgroup of measurements; the count must equal the subgroup size of the characteristic (checked by the service).
 */
final class SpcSampleRequest extends QualityFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'measurements' => ['required', 'array', 'min:2', 'max:10'],
            'measurements.*' => ['required', 'numeric', 'between:-999999999,999999999'],
            'sampled_at' => ['nullable', 'date'],
            'lot' => ['nullable', 'string', 'max:80'],
            'instrument_id' => ['nullable', 'uuid', $this->ownRow('qms_instruments')],
        ];
    }
}
