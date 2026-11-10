<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * The shape of a password change. The current password and the second factor are checked by ChangePassword, which
 * counts the failures; here only the new password follows the policy.
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
            'code' => ['nullable', 'string', 'max:16'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.different' => __('password_change.errors.same_password'),
        ];
    }
}
