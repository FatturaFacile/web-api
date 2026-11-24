<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

/**
 * A change to the security of the account: the current password is asked again, so a stolen token or an unattended
 * session cannot turn two-factor authentication off or read new recovery codes.
 */
class ConfirmedPasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $user = $this->user();

                if (! $user instanceof User || ! is_string($value) || ! Hash::check($value, (string) $user->password)) {
                    $fail(__('two_factor.errors.password_invalid'));
                }
            }],
        ];
    }
}
