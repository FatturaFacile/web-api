<?php

declare(strict_types=1);

namespace Modules\Treasury\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Treasury\Services\IbanValidator;

final class ValidIban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! app(IbanValidator::class)->isValid($value)) {
            $fail('This is not a valid IBAN.');
        }
    }
}
