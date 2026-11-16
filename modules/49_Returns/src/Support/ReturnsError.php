<?php

declare(strict_types=1);

namespace Modules\Returns\Support;

use Illuminate\Validation\ValidationException;

/**
 * A business rule violation of the returns module, reported as a 422 with a translated message.
 */
final class ReturnsError
{
    /**
     * @param  array<string, scalar|null>  $parameters
     */
    public static function on(string $field, string $key, array $parameters = []): ValidationException
    {
        return ValidationException::withMessages([$field => [__('returns_errors.'.$key, $parameters)]]);
    }
}
