<?php

declare(strict_types=1);

namespace Modules\Associations\DTOs;

use Carbon\CarbonImmutable;

final readonly class FeePaymentRequest
{
    public function __construct(
        public string $reference,
        public string $amount,
        public string $currency,
        public string $description,
        public CarbonImmutable $expiresAt,
    ) {}
}
