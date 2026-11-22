<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A decision on a count session: approving or rejecting an approval level (a rejection needs a reason), freezing,
 * releasing a stock freeze (needs a reason) or assigning the counter.
 */
final class CountDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reasonRequired = $this->routeIs('inventory.counts.approvals.reject') || $this->routeIs('inventory.counts.unfreeze');

        return [
            'reason' => [$reasonRequired ? 'required' : 'sometimes', 'nullable', 'string', 'max:1000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'assigned_to' => ['sometimes', 'nullable', 'uuid', 'exists:users,id'],
        ];
    }
}
