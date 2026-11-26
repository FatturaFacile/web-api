<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\Services;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use JsonSerializable;
use Stringable;
use UnitEnum;

/**
 * Engine independent canonical JSON. Object keys are sorted (recursively), lists keep their order, decimal numbers are
 * strings without an exponent, timestamps are ISO 8601 in UTC with microseconds and text is Unicode NFC, so a document
 * read back from SQLite, PostgreSQL or a JSON column re-serialises to exactly the bytes that were hashed.
 */
final class CanonicalSerializer
{
    public const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    public static function json(mixed $value): string
    {
        return (string) json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function canonicalize(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return self::decimal($value);
        }

        if (is_string($value)) {
            return self::text($value);
        }

        if ($value instanceof DateTimeInterface) {
            return self::timestamp($value);
        }

        if ($value instanceof BackedEnum) {
            return self::canonicalize($value->value);
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof JsonSerializable) {
            return self::canonicalize($value->jsonSerialize());
        }

        if ($value instanceof Stringable) {
            return self::text((string) $value);
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            return self::canonicalizeArray($value);
        }

        return null;
    }

    public static function timestamp(DateTimeInterface $moment): string
    {
        return Carbon::instance($moment)->setTimezone('UTC')->format(self::TIMESTAMP_FORMAT);
    }

    /**
     * A float as a plain decimal string: "1.5", "0.0001", "100" (never "1.0E+25" or a locale dependent comma).
     */
    public static function decimal(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            return $value > 0 ? 'Infinity' : (is_nan($value) ? 'NaN' : '-Infinity');
        }

        $formatted = rtrim(rtrim(number_format($value, 12, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }

    /**
     * @param  array<array-key, mixed>  $array
     * @return array<array-key, mixed>
     */
    private static function canonicalizeArray(array $array): array
    {
        $isList = array_is_list($array);
        $result = [];

        foreach ($array as $key => $item) {
            $result[$isList ? $key : (string) $key] = self::canonicalize($item);
        }

        if (! $isList) {
            ksort($result, SORT_STRING);
        }

        return $result;
    }

    private static function text(string $value): string
    {
        if (class_exists(\Normalizer::class) && mb_check_encoding($value, 'UTF-8')) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);

            return $normalized === false ? $value : $normalized;
        }

        return $value;
    }
}
