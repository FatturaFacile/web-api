<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A value that is text or a number (never a list or an object) of at most a given length, for free-form maps such as
 * template variables and channel credentials.
 */
final readonly class ScalarText implements ValidationRule
{
    /**
     * Create the rule.
     */
    public function __construct(private int $maxLength) {}

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && ! is_scalar($value)) {
            $fail("The {$attribute} must be text or a number.");

            return;
        }

        if (mb_strlen((string) $value) > $this->maxLength) {
            $fail("The {$attribute} may not be longer than {$this->maxLength} characters.");
        }
    }
}
