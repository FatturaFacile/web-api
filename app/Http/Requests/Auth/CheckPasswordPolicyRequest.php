<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A password typed on the registration, reset or change screen, checked as the user types. Public: the optional tenant
 * and email only choose which policy to check against (the platform defaults without them).
 */
class CheckPasswordPolicyRequest extends FormRequest
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
            'password' => ['present', 'nullable', 'string', 'max:256'],
            'tenant_id' => ['sometimes', 'nullable', 'uuid'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ];
    }
}
