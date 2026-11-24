<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * A manually edited or counterparty text stored as the next document version.
 */
final class StoreDocumentVersionRequest extends AuthoringFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:500000'],
            'source' => ['sometimes', Rule::in(['edit', 'counterparty'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
