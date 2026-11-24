<?php

declare(strict_types=1);

namespace Modules\Plm\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * The decision of the approver whose turn it is; a rejection needs a comment.
 */
final class DecisionRequest extends PlmFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
