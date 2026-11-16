<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Support;

use Illuminate\Validation\ValidationException;

/**
 * A business rule violation, answered as a 422 with a translated message on the offending field.
 */
final class Rejection
{
    /**
     * @param  array<string, scalar>  $replace
     */
    public static function on(string $field, string $key, array $replace = []): ValidationException
    {
        return ValidationException::withMessages([$field => [__('talent_learning_errors.'.$key, $replace)]]);
    }
}
