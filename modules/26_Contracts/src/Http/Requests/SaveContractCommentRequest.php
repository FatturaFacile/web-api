<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

/**
 * A review comment on a document version.
 */
final class SaveContractCommentRequest extends AuthoringFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:5000'], 'line_number' => ['nullable', 'integer', 'min:1', 'max:1000000']];
    }
}
