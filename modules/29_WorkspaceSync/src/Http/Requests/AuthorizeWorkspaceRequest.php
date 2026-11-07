<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AuthorizeWorkspaceRequest extends FormRequest
{
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
