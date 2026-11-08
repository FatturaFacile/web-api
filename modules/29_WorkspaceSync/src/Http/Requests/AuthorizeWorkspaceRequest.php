<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the input of the authorize workspace operation (authorization is decided by module.access).
 */
final class AuthorizeWorkspaceRequest extends FormRequest
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
            'consent' => ['required', 'accepted'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'distinct', 'in:calendar,mail'],
        ];
    }
}
