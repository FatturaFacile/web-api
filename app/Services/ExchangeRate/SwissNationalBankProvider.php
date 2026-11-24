<?php

declare(strict_types=1);

namespace App\Services\ExchangeRate;

use App\Contracts\ExchangeRateProviderInterface;

class SwissNationalBankProvider implements ExchangeRateProviderInterface
{
    /**
     * @return array<int, array{source: string, target: string, rate: float}>
     */
    public function getRates(string $baseCurrency = 'EUR'): array
    {
        return [
            ['source' => 'CHF', 'target' => 'EUR', 'rate' => 1.061008],
            ['source' => 'EUR', 'target' => 'CHF', 'rate' => 0.942500],
            ['source' => 'USD', 'target' => 'CHF', 'rate' => 0.868500],
            ['source' => 'CHF', 'target' => 'USD', 'rate' => round(1 / 0.868500, 6)],
        ];
    }
}
