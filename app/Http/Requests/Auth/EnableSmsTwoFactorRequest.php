<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Services\Sms\PhoneNumber;
use Closure;

/**
 * Starts the enrolment of the SMS factor: the password is asked again and the phone number must be in E.164 format
 * (spaces, dots, dashes and brackets typed by the user are removed first).
 */
class EnableSmsTwoFactorRequest extends ConfirmedPasswordRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => PhoneNumber::normalize($this->input('phone'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'phone' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! PhoneNumber::isValid($value)) {
                    $fail(__('two_factor.errors.phone_invalid'));
                }
            }],
        ];
    }
}
