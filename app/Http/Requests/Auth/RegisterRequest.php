<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Services\Auth\TaxIdentifier;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
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
            'country' => ['required', 'string', 'in:IT,CH'],
            'tax_id' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                if (TaxIdentifier::normalize((string) $value, (string) $this->input('country')) === null) {
                    $fail(__('registration.errors.invalid_tax_id'));
                }
            }],
            'terms_accepted' => ['accepted'],
            'privacy_accepted' => ['accepted'],
        ];
    }
}
