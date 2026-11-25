<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Support;

/**
 * An optional field that arrives as null from a form must leave a NOT NULL column at its default instead of overwriting it.
 */
final class NotNull
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $columns  the NOT NULL columns of the table that have a default
     * @return array<string, mixed>
     */
    public static function strip(array $data, array $columns): array
    {
        foreach ($columns as $column) {
            if (array_key_exists($column, $data) && $data[$column] === null) {
                unset($data[$column]);
            }
        }

        return $data;
    }
}
