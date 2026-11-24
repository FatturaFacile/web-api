<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A role and permissions to test against the rules before they are assigned.
 */
final class SodCheckRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
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
            'role' => ['required', 'string', 'max:50'],
            'permissions' => ['sometimes', 'array', 'max:100'],
            'permissions.*' => ['string', 'max:120'],
        ];
    }
}
