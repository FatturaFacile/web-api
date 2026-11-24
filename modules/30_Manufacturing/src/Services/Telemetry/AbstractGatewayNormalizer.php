<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Telemetry;

use Carbon\CarbonImmutable;
use Modules\Manufacturing\Models\MfgMachineSignalMap;
use Throwable;

/**
 * Shared parsing of the normalizers: timestamps (ISO 8601 or epoch seconds or milliseconds), JSON value paths and the
 * default decoding of a scalar.
 */
abstract class AbstractGatewayNormalizer implements GatewayMessageNormalizer
{
    /**
     * Exact comparison of the keys; the MQTT normalizer adds topic wildcards.
     */
    public function matches(string $incomingKey, string $configuredKey): bool
    {
        return $incomingKey === $configuredKey;
    }

    /**
     * The raw value, read at the value path of the mapping when it has one and the value is structured.
     */
    public function decode(RawSignal $signal, MfgMachineSignalMap $map): mixed
    {
        $value = $signal->value;

        if (is_array($value) && $map->value_path !== null && $map->value_path !== '') {
            return self::path($value, $map->value_path);
        }

        return is_array($value) && array_key_exists('value', $value) ? $value['value'] : $value;
    }

    /**
     * Reads a dotted path (a.b.0.c) of a decoded JSON structure; null when a step is missing.
     *
     * @param  array<array-key, mixed>  $data
     */
    protected static function path(array $data, string $path): mixed
    {
        $current = $data;

        foreach (explode('.', $path) as $step) {
            if (! is_array($current) || ! array_key_exists($step, $current)) {
                return null;
            }

            $current = $current[$step];
        }

        return $current;
    }

    /**
     * The time of a value: ISO 8601 text, epoch seconds or epoch milliseconds; the arrival time when absent or unreadable.
     */
    protected static function time(mixed $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if (is_numeric($value)) {
            $number = (float) $value;

            return CarbonImmutable::createFromTimestampUTC((int) ($number > 100_000_000_000 ? intdiv((int) $number, 1000) : $number));
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return CarbonImmutable::parse($value);
            } catch (Throwable) {
                return $fallback;
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected static function requireString(array $message, string $key): string
    {
        $value = $message[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidGatewayMessageException(sprintf('The message has no "%s".', $key));
        }

        return $value;
    }
}
