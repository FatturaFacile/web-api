<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Payload to close a case as resolved or written off.
 */
final class CloseCaseRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in(['resolved', 'written_off'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
