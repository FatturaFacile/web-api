<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

/**
 * The traffic light thresholds (percent of the budget) and the default validity of an approved override.
 */
final class SettingsRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amber_threshold_percent' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'red_threshold_percent' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'override_validity_days' => ['sometimes', 'integer', 'min:1', 'max:366'],
        ];
    }
}
