<?php

declare(strict_types=1);

namespace Modules\Integrations\DTOs;

/**
 * The payment page opened by a provider: its reference at the provider, which its confirmations repeat, and the URL.
 */
final readonly class PaymentLinkCreated
{
    public function __construct(public string $reference, public string $url) {}
}
