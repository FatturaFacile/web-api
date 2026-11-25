<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

/**
 * A formal revision of an approved budget: the reason is mandatory and stays with the revised version.
 */
final class ReviseRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
