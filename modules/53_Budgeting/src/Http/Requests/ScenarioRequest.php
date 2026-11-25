<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * A scenario (best case, worst case or custom) built from a version with every amount scaled by a percentage.
 */
final class ScenarioRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scenario_kind' => ['required', Rule::in(BudgetVersion::ScenarioKinds)],
            'adjustment_percent' => ['nullable', 'numeric', 'min:-100', 'max:1000'],
        ];
    }
}
