<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An http(s) URL that does not point at the local machine or a private network, because the server calls it on
 * behalf of a tenant. Only literal hosts are checked; names are not resolved.
 */
class PublicHttpUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) ? parse_url($value) : false;
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || $host === '' || $this->isInternal($host)) {
            $fail(__('ecommerce.errors.invalid_url'));
        }
    }

    private function isInternal(string $host): bool
    {
        $host = trim($host, '[]');

        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.localhost')) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
