<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Requests;

/**
 * A query of the pivot explorer that is not saved: same shape as a saved query, nothing is stored.
 */
final class AdHocQueryRequest extends BiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'dataset' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/'],
            'spec' => ['required', 'array'],
            'calculated_measures' => ['nullable', 'array', 'max:8'],
            'calculated_measures.*' => ['string', 'max:41'],
            ...$this->pivotRules(),
        ];
    }
}
