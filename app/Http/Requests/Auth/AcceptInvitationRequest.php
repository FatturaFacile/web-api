<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Auth\Security\PasswordPolicy;
use App\Services\Tenancy\AccessInvitations;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Accepting an invitation. An address without an account sends a name and a password; whoever already has an account
 * sends nothing but is signed in with it.
 */
class AcceptInvitationRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'min:2', 'max:250'],
            'password' => ['sometimes', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * The signed-in user of the session, who may accept without a body.
     */
    public function sessionUser(): ?User
    {
        $user = $this->user('sanctum');

        return $user instanceof User ? $user : null;
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (is_string($this->input('password'))) {
                    foreach (app(PasswordPolicy::class)->platformViolations((string) $this->input('password')) as $message) {
                        $validator->errors()->add('password', $message);
                    }

                    if ($validator->errors()->isNotEmpty()) {
                        return;
                    }
                }

                $token = (string) $this->route('token');
                $preview = app(AccessInvitations::class)->preview($token);

                if ($preview !== null && ! $preview['has_account']) {
                    foreach (['name', 'password'] as $field) {
                        if (! $this->filled($field)) {
                            $validator->errors()->add($field, __('validation.required', ['attribute' => $field]));
                        }
                    }
                }
            },
        ];
    }
}
