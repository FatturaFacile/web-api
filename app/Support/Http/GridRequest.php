<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * The grid part of a list request.
 *
 * Query string contract:
 *  - `sort_by=<column>&sort_dir=asc|desc`
 *  - `filter[<column>][]=a&filter[<column>][]=b`   value list (`__empty__` selects the empty cells)
 *  - `filter_like[<column>]=text`                  case-insensitive "contains"
 *  - `grid_group_by=<column>`                      rows are ordered by the group column over the whole dataset
 *  - `grid_values=<column>[&grid_scope=filtered]`  returns `[{value, count}]` of the whole dataset instead of a page
 *
 * A `<column>` is a real column, a `relation.column` path, a column declared by the model in `gridColumns()`
 * or a model accessor.
 */
final readonly class GridRequest
{
    public const string EMPTY = '__empty__';

    /**
     * @param  array<string, list<string>>  $filters
     * @param  array<string, string>  $like
     */
    public function __construct(
        public array $filters = [],
        public array $like = [],
        public ?string $sortBy = null,
        public string $sortDirection = 'asc',
        public ?string $groupBy = null,
        public ?string $valuesColumn = null,
        public bool $valuesFiltered = false,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $filters = [];
        $raw = $request->query('filter');
        foreach (is_array($raw) ? $raw : [] as $column => $values) {
            $values = array_values(array_filter((array) $values, static fn ($v): bool => is_scalar($v) && $v !== ''));
            if (self::isColumnName($column) && $values !== []) {
                $filters[$column] = array_map('strval', $values);
            }
        }

        $like = [];
        $raw = $request->query('filter_like');
        foreach (is_array($raw) ? $raw : [] as $column => $text) {
            if (self::isColumnName($column) && is_string($text) && trim($text) !== '') {
                $like[$column] = trim($text);
            }
        }

        return new self(
            filters: $filters,
            like: $like,
            sortBy: self::columnParameter($request, 'sort_by'),
            sortDirection: strtolower((string) $request->query('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc',
            groupBy: self::columnParameter($request, 'grid_group_by'),
            valuesColumn: self::columnParameter($request, 'grid_values'),
            valuesFiltered: $request->query('grid_scope') === 'filtered',
        );
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return array_values(array_unique(array_filter([
            ...array_keys($this->filters),
            ...array_keys($this->like),
            $this->sortBy,
            $this->groupBy,
        ])));
    }

    private static function columnParameter(Request $request, string $name): ?string
    {
        $value = $request->query($name);

        return self::isColumnName($value) ? $value : null;
    }

    private static function isColumnName(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*){0,3}$/', $value) === 1;
    }
}
