<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the input of the workspace callback operation (authorization is decided by module.access).
 */
final class WorkspaceCallbackRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:4096'],
            'state' => ['required', 'string', 'max:128'],
        ];
    }
}
