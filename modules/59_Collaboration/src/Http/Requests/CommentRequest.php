<?php

declare(strict_types=1);

namespace Modules\Collaboration\Http\Requests;

/**
 * Creates or edits a comment. The mentions are the ids of members of the company (the client picks them from the
 * mentionable users), so no free text is parsed for names.
 */
final class CommentRequest extends CollaborationFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'mentions' => ['nullable', 'array', 'max:20'],
            'mentions.*' => ['uuid', 'distinct', $this->tenantUser()],
        ];
    }
}
