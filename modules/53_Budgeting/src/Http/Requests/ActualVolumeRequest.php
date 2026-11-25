<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

/**
 * The quantity actually consumed on an account (and cost centre and project) in a month, for the volume and price analysis.
 */
final class ActualVolumeRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'uuid'],
            'cost_center_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'month' => ['required', 'date_format:Y-m'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
