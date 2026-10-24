<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a base64 (optionally data-URI prefixed) PNG signature image with a size cap.
 */
class PngSignature implements ValidationRule
{
    public const MAX_KILOBYTES = 512;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $binary = is_string($value) ? self::decode($value) : null;

        if ($binary === null || strlen($binary) > self::MAX_KILOBYTES * 1024 || ! str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            $fail(__('sales.signature_invalid', ['max' => self::MAX_KILOBYTES]));
        }
    }

    /**
     * Decode a base64 string (with optional `data:image/png;base64,` prefix) into binary, or null when invalid.
     */
    public static function decode(string $value): ?string
    {
        $payload = preg_replace('/^data:image\/png;base64,/', '', trim($value));
        $binary = base64_decode((string) $payload, true);

        return $binary === false ? null : $binary;
    }
}
