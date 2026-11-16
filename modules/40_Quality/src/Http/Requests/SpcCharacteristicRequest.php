<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Quality\Models\QmsSpcCharacteristic;

/**
 * A characteristic tracked on an X-bar/R chart: specification limits and the subgroup size (2 to 10).
 */
final class SpcCharacteristicRequest extends QualityFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:190'],
            'unit' => ['nullable', 'string', 'max:20'],
            'lower_spec_limit' => ['nullable', 'numeric', 'between:-999999999,999999999'],
            'upper_spec_limit' => ['nullable', 'numeric', 'between:-999999999,999999999'],
            'target' => ['nullable', 'numeric', 'between:-999999999,999999999'],
            'subgroup_size' => ['required', 'integer', 'between:2,10'],
            'instrument_id' => ['nullable', 'uuid', $this->ownRow('qms_instruments')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->isMethod('PUT') ? [...$rules, 'status' => ['sometimes', Rule::in(QmsSpcCharacteristic::Statuses)]] : $rules;
    }
}
