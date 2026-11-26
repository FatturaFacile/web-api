<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Entities;

use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;

/**
 * One published or entered rate: 1 base = rate quote on a day. A rate without tenant is official and shared by every company;
 * a rate with a tenant (manual or contract) belongs to that company only. A rate is never edited: a correction is a new
 * version and the old one points to it (`supersededBy`).
 */
final readonly class ExchangeRate
{
    public function __construct(
        public ?string $id,
        public CurrencyPair $pair,
        public ExchangeRateValue $rate,
        public RateType $type,
        public RateSource $source,
        public string $validOn,
        public bool $isDerived = false,
        public ?string $supersededBy = null,
        public ?string $tenantId = null,
        public ?string $reason = null,
        public ?string $contractReference = null,
    ) {}

    public function isSuperseded(): bool
    {
        return $this->supersededBy !== null;
    }

    public function isVisibleTo(?string $tenantId): bool
    {
        return $this->tenantId === null || $this->tenantId === $tenantId;
    }
}
