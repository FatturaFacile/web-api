<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\BusinessIntelligence\Models\BiSavedQuery;

/**
 * A dashboard and the filters that apply to all its widgets.
 */
final class DashboardRequest extends BiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->forMethod(['name' => ['required', 'string', 'max:160']]),
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', Rule::in([BiSavedQuery::Private, BiSavedQuery::Company])],
            'filters' => ['nullable', 'array'],
            ...DashboardDataRequest::filterRules('filters.'),
        ];
    }
}
