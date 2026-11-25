<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Budgeting\Models\BudgetImport;

/**
 * The upload of a budget file (xlsx or csv): replace the version or merge into it, or only validate.
 */
final class ImportRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'extensions:xlsx,xls,csv'],
            'mode' => ['nullable', Rule::in(BudgetImport::Modes)],
            'validate_only' => ['nullable', 'boolean'],
        ];
    }
}
