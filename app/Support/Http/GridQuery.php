<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Server-side engine of every grid: filters, sorting, grouping and distinct column values over the WHOLE dataset
 * of a list query, then pagination. The request contract is documented on {@see GridRequest}.
 *
 * Everything runs as portable SQL (identical on SQLite and PostgreSQL). Columns that only exist in PHP (model
 * accessors) are evaluated in memory over at most {@see self::MEMORY_CAP} rows.
 *
 * Used through the `gridPaginate()` macro of the Eloquent and query builders.
 */
final class GridQuery
{
    public const int MEMORY_CAP = 5000;

    public const int VALUES_LIMIT = 1000;

    /**
     * @param  array<int|string, mixed>  $arguments  same arguments as `paginate()`
     */
    public static function paginate(EloquentBuilder|Builder $query, array $arguments = []): LengthAwarePaginatorContract
    {
        $grid = GridRequest::fromRequest(request());
        $columns = new GridColumns($query);

        if (! $columns->isUsable()) {
            return $query->paginate(...$arguments);
        }

        if ($grid->valuesColumn !== null) {
            self::respondWithValues($query, $columns, $grid);
        }

        $memory = self::memoryColumns($columns, $grid);
        if ($memory !== [] && $query instanceof EloquentBuilder) {
            return self::paginateInMemory($query, $columns, $grid, $memory, $arguments);
        }

        self::applyFilters($query, $columns, $grid);
        self::applyOrder($query, $columns, $grid);

        return $query->paginate(...$arguments);
    }

    /**
     * Distinct values (with row counts) of a column over the whole dataset, as sent to the filter lists and to the
     * group headers. Answers the request and stops the controller.
     */
    private static function respondWithValues(EloquentBuilder|Builder $query, GridColumns $columns, GridRequest $grid): never
    {
        $column = (string) $grid->valuesColumn;
        $kind = $columns->kind($column);
        $values = [];

        if ($kind === GridColumns::SQL && ! self::isAggregated($query)) {
            $work = clone $query;
            if ($grid->valuesFiltered) {
                self::applyFilters($work, $columns, $grid);
            }
            $values = self::sqlValues($work, $columns, $column);
        } elseif ($kind !== null && $query instanceof EloquentBuilder) {
            $work = clone $query;
            if ($grid->valuesFiltered) {
                self::applyFilters($work, $columns, $grid);
            }
            $values = self::memoryValues($work, $columns, $column);
        }

        $truncated = count($values) > self::VALUES_LIMIT;

        throw new HttpResponseException(response()->json([
            'success' => true,
            'message' => 'Grid values retrieved successfully',
            'data' => array_slice($values, 0, self::VALUES_LIMIT),
            'meta' => ['column' => $column, 'truncated' => $truncated],
        ]));
    }

    /**
     * @return list<array{value: string|null, count: int}>
     */
    private static function sqlValues(EloquentBuilder|Builder $work, GridColumns $columns, string $column): array
    {
        $expression = $columns->expression($column);
        if ($expression === null) {
            return [];
        }

        $inner = $work instanceof EloquentBuilder ? $work->toBase() : clone $work;
        $inner->columns = null;
        $inner->orders = null;
        $inner->limit = null;
        $inner->offset = null;
        $inner->bindings['select'] = [];
        $inner->bindings['order'] = [];
        $inner->selectRaw("{$expression->asText()} as grid_value", $expression->bindings);

        $outer = $inner->newQuery()->fromSub($inner, 'grid_source')
            ->selectRaw('grid_value, COUNT(*) as grid_count')
            ->groupBy('grid_value')
            ->orderByRaw('CASE WHEN grid_value IS NULL THEN 1 ELSE 0 END')
            ->orderBy('grid_value')
            ->limit(self::VALUES_LIMIT + 1);

        return $outer->get()->map(static fn (object $row): array => [
            'value' => $row->grid_value === null ? null : (string) $row->grid_value,
            'count' => (int) $row->grid_count,
        ])->all();
    }

    /**
     * @return list<array{value: string|null, count: int}>
     */
    private static function memoryValues(EloquentBuilder $work, GridColumns $columns, string $column): array
    {
        $models = self::loadForMemory($work, $columns, [$column]);
        $counts = [];
        foreach ($models as $model) {
            $key = $columns->memoryValue($model, $column);
            $counts[$key ?? "\0"] = ($counts[$key ?? "\0"] ?? 0) + 1;
        }
        uksort($counts, static fn (string|int $a, string|int $b): int => self::compare($a === "\0" ? null : (string) $a, $b === "\0" ? null : (string) $b));

        return array_map(
            static fn (string|int $key, int $count): array => ['value' => $key === "\0" ? null : (string) $key, 'count' => $count],
            array_keys($counts),
            array_values($counts),
        );
    }

    private static function applyFilters(EloquentBuilder|Builder $query, GridColumns $columns, GridRequest $grid): void
    {
        foreach ($grid->filters as $column => $values) {
            $expression = $columns->kind($column) === GridColumns::SQL ? $columns->expression($column) : null;
            if ($expression === null) {
                continue;
            }
            $sql = [];
            $bindings = [];
            $real = array_values(array_diff($values, [GridRequest::EMPTY]));
            if ($real !== []) {
                $sql[] = $expression->asText().' IN ('.implode(', ', array_fill(0, count($real), '?')).')';
                $bindings = [...$expression->bindings, ...$real];
            }
            if (count($real) !== count($values)) {
                $sql[] = "COALESCE({$expression->cast()}, '') = ''";
                $bindings = [...$bindings, ...$expression->bindings];
            }
            $query->whereRaw('('.implode(' OR ', $sql).')', $bindings);
        }

        foreach ($grid->like as $column => $text) {
            $expression = $columns->kind($column) === GridColumns::SQL ? $columns->expression($column) : null;
            if ($expression === null) {
                continue;
            }
            $needle = '%'.mb_strtolower(str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text)).'%';
            $query->whereRaw("LOWER({$expression->asText()}) LIKE ? ESCAPE '!'", [...$expression->bindings, $needle]);
        }
    }

    private static function applyOrder(EloquentBuilder|Builder $query, GridColumns $columns, GridRequest $grid): void
    {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $group = $grid->groupBy !== null && $columns->kind($grid->groupBy) === GridColumns::SQL ? $grid->groupBy : null;
        $sort = $grid->sortBy !== null && $columns->kind($grid->sortBy) === GridColumns::SQL ? $grid->sortBy : null;

        if ($group === null && $sort === null) {
            return;
        }

        $keep = $sort === null ? ($base->orders ?? []) : [];
        $keepBindings = $sort === null ? ($base->bindings['order'] ?? []) : [];
        $query->reorder();

        if ($group !== null) {
            self::orderBy($query, $columns, $group, 'asc');
        }
        if ($sort !== null && $sort !== $group) {
            self::orderBy($query, $columns, $sort, $grid->sortDirection);
        }

        foreach ($keep as $order) {
            $base->orders[] = $order;
        }
        $base->bindings['order'] = [...($base->bindings['order'] ?? []), ...$keepBindings];

        $id = $sort !== null && $sort !== 'id' ? $columns->expression('id') : null;
        if ($id !== null) {
            $query->orderByRaw($id->sql);
        }
    }

    private static function orderBy(EloquentBuilder|Builder $query, GridColumns $columns, string $column, string $direction): void
    {
        $expression = $columns->expression($column);
        if ($expression === null) {
            return;
        }
        $query->orderByRaw("CASE WHEN {$expression->sql} IS NULL THEN 1 ELSE 0 END", $expression->bindings);
        $query->orderByRaw("{$expression->sql} {$direction}", $expression->bindings);
    }

    /**
     * @return list<string> the requested columns that need PHP to be evaluated
     */
    private static function memoryColumns(GridColumns $columns, GridRequest $grid): array
    {
        return array_values(array_filter(
            $grid->columns(),
            static fn (string $column): bool => $columns->kind($column) === GridColumns::MEMORY,
        ));
    }

    /**
     * @param  list<string>  $memory
     * @param  array<int|string, mixed>  $arguments
     */
    private static function paginateInMemory(EloquentBuilder $query, GridColumns $columns, GridRequest $grid, array $memory, array $arguments): LengthAwarePaginatorContract
    {
        self::applyFilters($query, $columns, $grid);

        $models = self::loadForMemory($query, $columns, $memory)->all();

        $value = static fn ($model, string $column): ?string => $columns->kind($column) === GridColumns::MEMORY
            ? $columns->memoryValue($model, $column)
            : GridColumns::text($model->getAttribute($column));

        foreach ($grid->filters as $column => $values) {
            if (! in_array($column, $memory, true)) {
                continue;
            }
            $models = array_filter($models, static function ($model) use ($value, $column, $values): bool {
                $text = $value($model, $column);

                return in_array($text ?? GridRequest::EMPTY, $values, true) || ($text === '' && in_array(GridRequest::EMPTY, $values, true));
            });
        }
        foreach ($grid->like as $column => $text) {
            if (! in_array($column, $memory, true)) {
                continue;
            }
            $models = array_filter($models, static fn ($model): bool => str_contains(mb_strtolower((string) $value($model, $column)), mb_strtolower($text)));
        }

        $models = array_values($models);
        $keys = array_values(array_filter([
            $grid->groupBy !== null ? [$grid->groupBy, 'asc'] : null,
            $grid->sortBy !== null && $grid->sortBy !== $grid->groupBy ? [$grid->sortBy, $grid->sortDirection] : null,
        ]));
        if ($keys !== []) {
            usort($models, static function ($a, $b) use ($keys, $value): int {
                foreach ($keys as [$column, $direction]) {
                    $left = $value($a, $column);
                    $right = $value($b, $column);
                    $result = self::compare($left, $right);
                    if ($result !== 0) {
                        // Empty cells always come last, whatever the direction.
                        return ($left === null || $right === null || $direction === 'asc') ? $result : -$result;
                    }
                }

                return 0;
            });
        }

        $perPage = (int) ($arguments['perPage'] ?? $arguments[0] ?? $query->getModel()->getPerPage());
        $pageName = (string) ($arguments['pageName'] ?? $arguments[2] ?? 'page');
        $page = Paginator::resolveCurrentPage($pageName);

        return new LengthAwarePaginator(
            new EloquentCollection(array_slice($models, ($page - 1) * $perPage, $perPage)),
            count($models),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName],
        );
    }

    /**
     * @param  list<string>  $memory
     * @return Collection<int, Model>
     */
    private static function loadForMemory(EloquentBuilder $query, GridColumns $columns, array $memory): Collection
    {
        $paths = array_values(array_filter(array_map($columns->eagerLoadFor(...), $memory)));
        if ($paths !== []) {
            $query->with($paths);
        }

        return $query->limit(self::MEMORY_CAP)->get();
    }

    private static function isAggregated(EloquentBuilder|Builder $query): bool
    {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;

        return ! empty($base->groups) || ! empty($base->havings) || ! empty($base->unions) || $base->distinct !== false;
    }

    /**
     * Natural, case-insensitive comparison; empty cells last.
     */
    private static function compare(?string $a, ?string $b): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 1 : 0) <=> ($b === null ? 1 : 0);
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a <=> (float) $b;
        }

        return strnatcasecmp($a, $b);
    }
}
