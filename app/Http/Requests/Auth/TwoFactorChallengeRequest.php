<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The answer to a second-factor challenge: one of an app code, an email code, a recovery code or a security key
 * assertion, and optionally the wish to remember the device.
 */
class TwoFactorChallengeRequest extends FormRequest
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
            'challenge_token' => ['required', 'string', 'size:64'],
            'code' => ['nullable', 'required_without_all:recovery_code,email_code,webauthn', 'string', 'max:16'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
            'email_code' => ['nullable', 'string', 'max:16'],
            'webauthn' => ['nullable', 'array'],
            'webauthn.ceremony_id' => ['required_with:webauthn', 'string', 'max:64'],
            'webauthn.credential' => ['required_with:webauthn', 'array'],
            'remember_device' => ['sometimes', 'boolean'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
