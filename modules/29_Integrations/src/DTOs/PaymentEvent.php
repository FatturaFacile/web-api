<?php

declare(strict_types=1);

namespace Modules\Integrations\DTOs;

final readonly class PaymentEvent
{
    public function __construct(
        public string $eventId,
        public string $reference,
        public string $amount,
        public string $currency,
    ) {}
}
