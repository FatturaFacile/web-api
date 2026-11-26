<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Handlers;

use InvalidArgumentException;
use Modules\Treasury\Currency\Application\Queries\GetRateQuery;
use Modules\Treasury\Currency\Domain\Entities\ResolvedRate;
use Modules\Treasury\Currency\Domain\Exceptions\CurrencyDomainException;
use Modules\Treasury\Currency\Domain\Exceptions\ExchangeRateMissingException;
use Modules\Treasury\Currency\Domain\Services\RateSelector;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;

/**
 * Answers which rate applies to a pair on a day.
 */
final readonly class GetRateHandler
{
    public function __construct(private RateSelector $selector) {}

    /**
     * @throws ExchangeRateMissingException 422 `exchange_rate_missing` when no rate is within the five-day window
     * @throws CurrencyDomainException
     */
    public function handle(GetRateQuery $query): ResolvedRate
    {
        try {
            $pair = CurrencyPair::of($query->base, $query->quote);
        } catch (InvalidArgumentException $exception) {
            throw new CurrencyDomainException($exception->getMessage(), 'invalid_currency', 422, 'base');
        }

        return $this->selector->select($pair, $query->date, $query->tenantId, $query->type, $query->contractReference);
    }
}
