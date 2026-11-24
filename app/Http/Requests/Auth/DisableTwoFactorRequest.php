<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

/**
 * Turning two factors off needs the password and a second factor: a code of the app or a recovery code.
 */
class DisableTwoFactorRequest extends ConfirmedPasswordRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'code' => ['nullable', 'required_without:recovery_code', 'string', 'max:16'],
            'recovery_code' => ['nullable', 'required_without:code', 'string', 'max:32'],
        ];
    }
}
