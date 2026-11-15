<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * A draft copy of a version, as a new budget or as a forecast.
 */
final class VersionCopyRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'version_type' => ['sometimes', Rule::in([BudgetVersion::Budget, BudgetVersion::Forecast])],
        ];
    }
}
