<?php

declare(strict_types=1);

namespace App\Contracts;

interface ExchangeRateProviderInterface
{
    /**
     * Retrieve current currency exchange rates.
     *
     * @return array<int, array{source: string, target: string, rate: float}>
     */
    public function getRates(string $baseCurrency = 'EUR'): array;
}
