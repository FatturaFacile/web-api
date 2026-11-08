<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Documents\Http\Requests\Concerns\ValidatesDocumentFields;

/**
 * Validates the input of the update document operation (authorization is decided by module.access).
 */
final class UpdateDocumentRequest extends FormRequest
{
    use ValidatesDocumentFields;

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
            ...$this->documentFieldRules(),
            'title' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:active,archived'],
        ];
    }
}
