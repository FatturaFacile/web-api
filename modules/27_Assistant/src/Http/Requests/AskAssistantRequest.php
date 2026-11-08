<?php

declare(strict_types=1);

namespace Modules\Assistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the input of the ask assistant operation (authorization is decided by module.access).
 */
final class AskAssistantRequest extends FormRequest
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
        return ['question' => ['required', 'string', 'max:500']];
    }
}
