<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Entities;

use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;

/**
 * The rate chosen for a pair and a day, with the day of the rate actually used (earlier than the asked day on weekends and
 * holidays) and whether it was derived (inverse or cross rate) or contractual.
 */
final readonly class ResolvedRate
{
    public function __construct(
        public CurrencyPair $pair,
        public ExchangeRateValue $rate,
        public RateType $type,
        public RateSource $source,
        public string $validOn,
        public string $requestedDate,
        public bool $isDerived,
        public bool $isContract,
        public ?string $rateId,
    ) {}

    public function isFallbackDate(): bool
    {
        return $this->validOn !== $this->requestedDate;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'base' => $this->pair->base->value,
            'quote' => $this->pair->quote->value,
            'rate' => $this->rate->value,
            'rate_type' => $this->type->value,
            'source' => $this->source->value,
            'valid_on' => $this->validOn,
            'requested_date' => $this->requestedDate,
            'is_derived' => $this->isDerived,
            'is_contract' => $this->isContract,
            'is_fallback_date' => $this->isFallbackDate(),
            'rate_id' => $this->rateId,
        ];
    }
}
