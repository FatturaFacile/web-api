<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\BusinessIntelligence\Models\BiSavedQuery;

/**
 * A saved query: dataset, specification of the semantic layer, calculated measures, pivot layout and snapshot schedule.
 * The specification itself is validated against the dataset by the semantic layer.
 */
final class SavedQueryRequest extends BiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->forMethod([
                'name' => ['required', 'string', 'max:160'],
                'dataset' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/'],
                'spec' => ['required', 'array'],
            ]),
            'description' => ['nullable', 'string', 'max:2000'],
            'calculated_measures' => ['nullable', 'array', 'max:8'],
            'calculated_measures.*' => ['string', 'max:41'],
            'visibility' => ['sometimes', Rule::in([BiSavedQuery::Private, BiSavedQuery::Company])],
            'snapshot_frequency' => ['nullable', Rule::in(BiSavedQuery::SnapshotFrequencies)],
            ...$this->pivotRules(),
        ];
    }
}
