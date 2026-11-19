<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * A SQL value expression of a grid column (already wrapped for the connection grammar) with its bindings.
 */
final readonly class GridExpression
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings = [],
        public bool $isColumn = false,
        public bool $isBoolean = false,
        public bool $isPostgres = false,
    ) {}

    /**
     * The expression as text, comparable with the string values sent by the frontend on every database.
     */
    public function asText(): string
    {
        if ($this->isPostgres && $this->isBoolean) {
            return "REPLACE(REPLACE(CAST({$this->sql} AS TEXT), 'true', '1'), 'false', '0')";
        }

        if ($this->isPostgres) {
            return "REGEXP_REPLACE(CAST({$this->sql} AS TEXT), '^(-?[0-9]+)(?:(\\.[0-9]*[1-9])0+|\\.0+)$', '\\1\\2')";
        }

        return $this->cast();
    }

    public function cast(): string
    {
        return "CAST({$this->sql} AS TEXT)";
    }
}
