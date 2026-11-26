<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Persistence;

use App\Models\ExchangeRate as ExchangeRateRecord;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Treasury\Currency\Domain\Entities\ExchangeRate;
use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;

/**
 * Maps a stored rate row to the domain entity. A row that is not a valid rate (zero or negative) is not a rate: null.
 * Days are normalised to `Y-m-d` whatever the engine stored (SQLite keeps `Y-m-d 00:00:00` text for a datetime-cast date).
 */
final class ExchangeRateMapper
{
    public static function toDomain(ExchangeRateRecord $record): ?ExchangeRate
    {
        try {
            return new ExchangeRate(
                id: (string) $record->getKey(),
                pair: CurrencyPair::of((string) $record->source, (string) $record->target),
                rate: ExchangeRateValue::of((string) $record->rate),
                type: RateType::tryFrom((string) $record->rate_type) ?? RateType::Mid,
                source: RateSource::tryFrom((string) $record->rate_source) ?? RateSource::Manual,
                validOn: self::day($record->getRawOriginal('effective_date')),
                isDerived: (bool) $record->is_derived,
                supersededBy: $record->superseded_by === null ? null : (string) $record->superseded_by,
                tenantId: $record->tenant_id === null ? null : (string) $record->tenant_id,
                reason: $record->reason,
                contractReference: $record->contract_reference,
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function day(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value)->toDateString();
    }
}
