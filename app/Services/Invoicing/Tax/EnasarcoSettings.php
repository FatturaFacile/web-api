<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Tax;

/**
 * ENASARCO contribution resolved for one invoice: rate and ceiling for the year, plus the commissions already
 * invoiced to the same principal in that calendar year.
 */
final readonly class EnasarcoSettings
{
    public function __construct(
        public string $rate,
        public string $annualMaximumCommission,
        public string $ytdCommissions = '0.00',
        public string $reason = 'R',
        public string $annualMinimumContribution = '0.00',
        public string $ytdContribution = '0.00',
    ) {}
}
