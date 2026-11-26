<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\Services;

/**
 * Builds the `changes` document of an entry: only the attributes that changed, as {"old": {...}, "new": {...}}.
 *  - created: old is empty, new holds the attributes;
 *  - updated: both hold the changed attributes (only those named in `after` are compared, so a caller may pass the full
 *    original state against the changed attributes);
 *  - deleted: new is empty, old holds the attributes.
 * Values are compared in their canonical form, so 10.0 and "10" are not a change, and the key order never matters.
 */
final class DiffBuilder
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    public function build(?array $before, ?array $after): array
    {
        if ($before === null && $after === null) {
            return ['old' => [], 'new' => []];
        }

        if ($before === null) {
            return ['old' => [], 'new' => (array) $after];
        }

        if ($after === null) {
            return ['old' => $before, 'new' => []];
        }

        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            $existed = array_key_exists($key, $before);

            if ($existed && CanonicalSerializer::json($before[$key]) === CanonicalSerializer::json($value)) {
                continue;
            }

            $old[$key] = $before[$key] ?? null;
            $new[$key] = $value;
        }

        return ['old' => $old, 'new' => $new];
    }
}
