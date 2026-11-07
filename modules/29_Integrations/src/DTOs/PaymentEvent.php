<?php

declare(strict_types=1);

namespace Modules\Integrations\DTOs;

/**
 * The outcome of a payment as the provider confirmed it. `paid` is false for a payment the provider reports as failed.
 */
final readonly class PaymentEvent
{
    public function __construct(
        public string $eventId,
        public string $reference,
        public string $amount,
        public string $currency,
        public bool $paid = true,
    ) {}
}
