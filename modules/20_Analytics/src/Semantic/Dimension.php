<?php

declare(strict_types=1);

namespace Modules\Analytics\Semantic;

use InvalidArgumentException;

/**
 * A column of a dataset the user may group or filter by. `sql` is declared in code and is the only text that ever
 * reaches the query as an expression: users pick dimensions by key, never by SQL.
 */
final readonly class Dimension
{
    public const string TypeString = 'string';

    public const string TypeNumber = 'number';

    public const string TypeDate = 'date';

    public const string TypeBoolean = 'boolean';

    /**
     * Truncations a date dimension can be grouped by.
     *
     * @var list<string>
     */
    public const array Granularities = ['day', 'week', 'month', 'quarter', 'year'];

    /**
     * @param  string  $sql  expression over the columns of the dataset tables, written by the developer
     * @param  string|null  $join  alias of the declared join the expression needs
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public string $sql,
        public ?string $join = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key) !== 1) {
            throw new InvalidArgumentException("Invalid dimension key [{$key}].");
        }

        if (! in_array($type, [self::TypeString, self::TypeNumber, self::TypeDate, self::TypeBoolean], true)) {
            throw new InvalidArgumentException("Invalid dimension type [{$type}].");
        }
    }

    /**
     * Operators the dimension accepts in a filter.
     *
     * @return list<string>
     */
    public function operators(): array
    {
        return match ($this->type) {
            self::TypeString => ['eq', 'neq', 'in', 'not_in', 'contains', 'starts_with', 'is_null', 'not_null'],
            self::TypeNumber => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'in', 'not_in', 'is_null', 'not_null'],
            self::TypeDate => ['eq', 'gt', 'gte', 'lt', 'lte', 'between', 'is_null', 'not_null'],
            default => ['eq', 'neq', 'is_null', 'not_null'],
        };
    }

    /**
     * @return array{key: string, label: string, type: string, operators: list<string>, granularities: list<string>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'operators' => $this->operators(),
            'granularities' => $this->type === self::TypeDate ? self::Granularities : [],
        ];
    }
}
