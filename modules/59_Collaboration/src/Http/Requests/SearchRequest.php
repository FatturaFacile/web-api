<?php

declare(strict_types=1);

namespace Modules\Collaboration\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Collaboration\Services\CollaborationRegistry;

/**
 * Query of the global search: the text, the record types to search and how many hits per type.
 */
final class SearchRequest extends CollaborationFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'types' => ['nullable', 'array'],
            'types.*' => ['string', Rule::in(array_keys(app(CollaborationRegistry::class)->all()))],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }
}
