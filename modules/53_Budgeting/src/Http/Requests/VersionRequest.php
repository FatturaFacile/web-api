<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * A budget version of a fiscal year. The fiscal year starts on year_start (the first of January of the year when left
 * out); seasonal_weights are the twelve weights of the seasonal allocation.
 */
final class VersionRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'year_start' => ['nullable', 'date_format:Y-m-d'],
            'version_type' => ['required', Rule::in(BudgetVersion::Types)],
            'currency' => ['required', 'string', 'size:3'],
            'seasonal_weights' => ['nullable', 'array', 'size:12'],
            'seasonal_weights.*' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);
    }
}
