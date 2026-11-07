<?php

declare(strict_types=1);

namespace App\Rules;

use App\Exceptions\UnsafeOutboundUrlException;
use App\Services\Security\OutboundUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A bare host name or IP (mail servers) that resolves to public addresses only: the server connects to it on behalf
 * of a tenant, so it must not reach the local machine, a private network or cloud metadata.
 */
class PublicHost implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail(__('The :attribute must be a public host name.', ['attribute' => $attribute]));

            return;
        }

        try {
            app(OutboundUrlGuard::class)->inspectHost($value, 443);
        } catch (UnsafeOutboundUrlException) {
            $fail(__('The :attribute must be a public host name.', ['attribute' => $attribute]));
        }
    }
}
