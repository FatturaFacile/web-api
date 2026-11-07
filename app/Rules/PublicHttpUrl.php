<?php

declare(strict_types=1);

namespace App\Rules;

use App\Exceptions\UnsafeOutboundUrlException;
use App\Services\Security\OutboundUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An http(s) URL whose host resolves to public addresses only, because the server calls it on behalf of a tenant.
 * The check is repeated (and the addresses pinned) on every request by {@see OutboundUrlGuard::protect()}: this rule
 * only rejects a bad address early, with a validation message.
 */
class PublicHttpUrl implements ValidationRule
{
    /**
     * @param  list<string>  $schemes
     */
    public function __construct(private readonly array $schemes = ['http', 'https'], private readonly ?string $message = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->inspect($value)) {
            $fail($this->message ?? __('ecommerce.errors.invalid_url'));
        }
    }

    private function inspect(string $url): bool
    {
        try {
            app(OutboundUrlGuard::class)->inspectUrl($url, $this->schemes);

            return true;
        } catch (UnsafeOutboundUrlException) {
            return false;
        }
    }
}
