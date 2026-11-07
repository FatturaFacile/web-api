<?php

declare(strict_types=1);

namespace Modules\Integrations\DTOs;

use Carbon\CarbonImmutable;

/**
 * What a provider needs to open a payment page: our own reference of the link, the amount as a bcmath string, the
 * currency, the text shown to the payer and, optionally, when the link stops being valid.
 */
final readonly class PaymentLinkRequest
{
    public function __construct(
        public string $reference,
        public string $amount,
        public string $currency,
        public string $description,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
