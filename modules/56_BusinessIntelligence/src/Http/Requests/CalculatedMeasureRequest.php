<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * A calculated measure. The dataset and the key are set on creation and never change afterwards.
 */
final class CalculatedMeasureRequest extends BiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $units = ['number', 'currency', 'count', 'quantity', 'percent'];

        if ($this->isMethod('PUT')) {
            return [
                'label' => ['sometimes', 'string', 'max:120'],
                'formula' => ['sometimes', 'string', 'max:500'],
                'unit' => ['sometimes', Rule::in($units)],
                'scale' => ['sometimes', 'integer', 'min:0', 'max:6'],
            ];
        }

        return [
            'dataset' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/'],
            'key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/'],
            'label' => ['required', 'string', 'max:120'],
            'formula' => ['required', 'string', 'max:500'],
            'unit' => ['sometimes', Rule::in($units)],
            'scale' => ['sometimes', 'integer', 'min:0', 'max:6'],
        ];
    }
}
