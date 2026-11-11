<?php

declare(strict_types=1);

namespace Modules\Analytics\Semantic;

use InvalidArgumentException;

/**
 * A table a dataset reaches from its base table. The join is always restricted to the tenant of the base row and, for
 * soft-deleted tables, to live rows, so a join can never cross a company boundary.
 */
final readonly class DatasetJoin
{
    /**
     * @param  string  $table  physical table, also its alias in the query
     * @param  string  $on  join condition written by the developer
     * @param  bool  $inner  an inner join also filters the base rows
     */
    public function __construct(
        public string $table,
        public string $on,
        public bool $inner = false,
        public bool $softDeletes = true,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,60}$/', $table) !== 1) {
            throw new InvalidArgumentException("Invalid join table [{$table}].");
        }
    }
}
