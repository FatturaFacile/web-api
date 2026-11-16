<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\CreditManagement\Models\CreditCollectionAction;

/**
 * Payload of an action done on a case.
 */
final class CaseActionRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'action_type' => ['required', Rule::in(array_values(array_diff(CreditCollectionAction::Types, ['dunning_step'])))],
            'channel' => ['nullable', 'string', 'max:30'],
            'performed_on' => ['nullable', 'date_format:Y-m-d'],
            'outcome' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
