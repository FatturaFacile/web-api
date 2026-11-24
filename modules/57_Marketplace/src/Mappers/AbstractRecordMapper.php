<?php

declare(strict_types=1);

namespace Modules\Marketplace\Mappers;

use Carbon\CarbonImmutable;
use Modules\Marketplace\Contracts\RecordMapper;
use Modules\Marketplace\Exceptions\RecordMappingException;
use Throwable;

/**
 * Field readers shared by the mappers: they turn the loose JSON of an external system into strict values or refuse the
 * record with a {@see RecordMappingException}.
 */
abstract class AbstractRecordMapper implements RecordMapper
{
    /**
     * The `connector => resources` this mapper handles.
     *
     * @return array<string, list<string>>
     */
    abstract protected function handled(): array;

    public function supports(string $connectorKey, string $resource): bool
    {
        return in_array($resource, $this->handled()[$connectorKey] ?? [], true);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function requiredId(array $record, string $key = 'id'): string
    {
        $value = $record[$key] ?? null;

        if ((! is_string($value) && ! is_int($value)) || (string) $value === '') {
            throw new RecordMappingException(__('marketplace_errors.mapping.missing_field', ['field' => $key]));
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function optionalString(array $record, string $key): ?string
    {
        $value = data_get($record, $key);

        return is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : null;
    }

    /**
     * A decimal amount as a string with two decimals.
     */
    protected function decimal(mixed $value, string $field): string
    {
        if (! is_numeric($value)) {
            throw new RecordMappingException(__('marketplace_errors.mapping.invalid_amount', ['field' => $field]));
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * An amount in the minor unit of its currency (Stripe) as a decimal string.
     */
    protected function minorUnits(mixed $value, string $currency, string $field): string
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit(ltrim($value, '-')))) {
            throw new RecordMappingException(__('marketplace_errors.mapping.invalid_amount', ['field' => $field]));
        }

        $zeroDecimal = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

        return in_array($currency, $zeroDecimal, true)
            ? number_format((float) $value, 0, '.', '')
            : number_format(((float) $value) / 100, 2, '.', '');
    }

    protected function currency(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z]{3}$/', $value) !== 1) {
            throw new RecordMappingException(__('marketplace_errors.mapping.invalid_currency'));
        }

        return strtoupper($value);
    }

    /**
     * An ISO 8601 UTC date from a date string or a Unix timestamp, null when the field is absent.
     */
    protected function isoDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = is_int($value) ? CarbonImmutable::createFromTimestampUTC($value) : CarbonImmutable::parse((string) $value)->utc();
        } catch (Throwable) {
            throw new RecordMappingException(__('marketplace_errors.mapping.invalid_date'));
        }

        return $date->format('Y-m-d\TH:i:s\Z');
    }
}
