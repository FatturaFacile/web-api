<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Events;

/**
 * A provider delivered its rates and they were stored (the rates of the day, plus the derived cross rates).
 */
final readonly class ExchangeRatesSynchronized
{
    /**
     * @param  list<string>  $currencies  currencies whose official rate was stored or updated
     */
    public function __construct(
        public string $runId,
        public string $provider,
        public int $ratesCount,
        public int $derivedCount,
        public string $latestRateDate,
        public array $currencies = [],
    ) {}
}
