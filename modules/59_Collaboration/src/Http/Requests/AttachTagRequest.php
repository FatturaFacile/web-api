<?php

declare(strict_types=1);

namespace Modules\Collaboration\Http\Requests;

/**
 * Attaches a tag to a record: an existing tag by id, or a tag by name that is created when the company has none yet.
 */
final class AttachTagRequest extends CollaborationFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'tag_id' => ['required_without:name', 'nullable', 'uuid', $this->ownRow('collab_tags')],
            'name' => ['required_without:tag_id', 'nullable', 'string', 'max:60'],
        ];
    }
}
