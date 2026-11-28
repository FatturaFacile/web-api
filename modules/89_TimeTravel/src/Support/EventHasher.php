<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Support;

/**
 * Canonical SHA-256 of an event, chained to the hash of the previous event of its stream: changing any stored field, or
 * removing, inserting or reordering an event, changes every following hash.
 */
final class EventHasher
{
    public const string GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param  array<string, mixed>  $fields  tenant_id, stream_id, aggregate_type, aggregate_id, sequence_number, event_type, valid_at, recorded_at, payload, metadata, actor_user_id
     */
    public static function hash(string $previousHash, array $fields): string
    {
        return hash('sha256', $previousHash.'|'.self::canonical($fields));
    }

    public static function canonical(mixed $value): string
    {
        return (string) json_encode(self::normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = array_map(self::normalize(...), $value);

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }
}
