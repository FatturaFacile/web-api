<?php

declare(strict_types=1);

namespace Modules\HumanResources\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\HumanResources\Services\Payroll\AhvNumberValidator;

/**
 * Swiss AHV/AVS number (756.XXXX.XXXX.XC) with a valid EAN-13 check digit.
 */
final class ValidAhvNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! AhvNumberValidator::isValid($value)) {
            $fail('The :attribute must be a valid Swiss AHV number (756.XXXX.XXXX.XC).');
        }
    }
}
