<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Documents\Services\DocumentLinkRegistry;

/**
 * Validates the input of the link document operation (authorization is decided by module.access).
 */
final class LinkDocumentRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'linkable_type' => ['required', 'string', Rule::in(array_keys(app(DocumentLinkRegistry::class)->targets()))],
            'linkable_id' => ['required', 'uuid'],
        ];
    }
}
