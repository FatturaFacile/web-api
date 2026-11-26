<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Exceptions;

use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;

/**
 * No rate is available on the day or within the look-back window before it. Never replaced by an invented rate.
 */
final class ExchangeRateMissingException extends CurrencyDomainException
{
    public const string CODE = 'exchange_rate_missing';

    public function __construct(public readonly CurrencyPair $pair, public readonly string $date, public readonly int $windowDays)
    {
        parent::__construct(
            __('currency_fx.errors.exchange_rate_missing', ['base' => $pair->base->value, 'quote' => $pair->quote->value, 'date' => $date, 'days' => $windowDays]),
            self::CODE,
            422,
            'exchange_rate',
        );
    }
}
