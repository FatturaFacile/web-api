<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\SupportedCountry;
use App\Services\Auth\Security\PasswordPolicy;
use App\Services\Auth\TaxIdentifier;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'company_name' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', Rule::enum(SupportedCountry::class)],
            'tax_id' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                if (TaxIdentifier::normalize((string) $value, (string) $this->input('country')) === null) {
                    $fail(__('registration.errors.invalid_tax_id'));
                }
            }],
            'terms_accepted' => ['accepted'],
            'privacy_accepted' => ['accepted'],
        ];
    }

    /**
     * The platform password policy, including the compromised-password check when the system default turns it on.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('password') || ! is_string($this->input('password'))) {
                    return;
                }

                foreach (app(PasswordPolicy::class)->platformViolations((string) $this->input('password')) as $message) {
                    $validator->errors()->add('password', $message);
                }
            },
        ];
    }
}
