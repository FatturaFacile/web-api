<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\ValueObjects;

use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\RateType;

/**
 * A rate as a provider published it: 1 base = rate quote on a day.
 */
final readonly class RateQuote
{
    public function __construct(
        public CurrencyPair $pair,
        public ExchangeRateValue $rate,
        public string $validOn,
        public RateType $type,
        public RateSource $source,
    ) {}
}
