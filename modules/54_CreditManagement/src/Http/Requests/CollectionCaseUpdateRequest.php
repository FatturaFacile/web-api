<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\CreditManagement\Models\CreditCollectionCase;

/**
 * Payload to change the priority, notes, collector or next action date of a case.
 */
final class CollectionCaseUpdateRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'priority' => ['sometimes', Rule::in(CreditCollectionCase::Priorities)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'collector_id' => ['sometimes', 'nullable', 'uuid', $this->tenantUser()],
            'next_action_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
