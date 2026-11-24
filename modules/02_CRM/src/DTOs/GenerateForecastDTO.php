<?php

declare(strict_types=1);

namespace Modules\CRM\DTOs;

final readonly class GenerateForecastDTO
{
    public function __construct(
        public string $tenantId,
        public string $period, // e.g. '2027-Q1', '2027-01'
        public ?string $userId = null,
        public string $currency = 'EUR',
        public float $quotaAmount = 0.00
    ) {}
}
