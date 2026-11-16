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
    public function __construct(public string $sql, public array $bindings = [], public bool $isColumn = false) {}

    /**
     * The expression as text, comparable with the string values sent by the frontend on every database.
     */
    public function asText(): string
    {
        return $this->cast();
    }

    public function cast(): string
    {
        return "CAST({$this->sql} AS TEXT)";
    }
}
