<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the input of the document check-out (access is decided by module.access).
 */
final class CheckOutDocumentRequest extends FormRequest
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
        return ['note' => ['nullable', 'string', 'max:500']];
    }
}
