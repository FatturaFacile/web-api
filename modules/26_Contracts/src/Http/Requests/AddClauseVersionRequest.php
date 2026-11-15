<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

/**
 * A new wording of a clause.
 */
final class AddClauseVersionRequest extends AuthoringFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:100000'], 'change_note' => ['nullable', 'string', 'max:500']];
    }
}
