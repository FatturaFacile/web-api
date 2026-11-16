<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

/**
 * A fallback wording of a clause.
 */
final class SaveFallbackRequest extends AuthoringFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:100000'],
            'rank' => ['nullable', 'integer', 'min:1', 'max:50'],
            'requires_approval' => ['sometimes', 'boolean'],
        ];
    }
}
