<?php

declare(strict_types=1);

namespace App\Support\Http;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Resolves the column keys sent by a grid to something the database (or, as a last resort, the model) can evaluate.
 *
 * Every key is validated against a whitelist built from the model, so a request can never reach an arbitrary method:
 *  1. a real, non-hidden column of the table;
 *  2. a column declared by the model with `gridColumns(): array<string, string>` (portable SQL expression);
 *  3. a `relation.column` path over typed to-one relations, evaluated as a correlated sub-select;
 *  4. a model accessor, appended attribute or any other to-one relation path: evaluated in PHP ("memory").
 *
 * 1-3 are "sql" columns (filter, sort, group and distinct values run in the database, identical on SQLite and
 * PostgreSQL); 4 are "memory" columns and are computed over at most `GridQuery::MEMORY_CAP` rows.
 */
final class GridColumns
{
    public const string SQL = 'sql';

    public const string MEMORY = 'memory';

    /** @var array<string, list<string>> */
    private static array $columnCache = [];

    private readonly QueryBuilder $base;

    private readonly ?Model $model;

    private readonly string $table;

    /** @var array<string, GridExpression|false> */
    private array $expressions = [];

    public function __construct(EloquentBuilder|QueryBuilder $query)
    {
        $this->model = $query instanceof EloquentBuilder ? $query->getModel() : null;
        $this->base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $from = $this->model?->getTable() ?? $this->base->from;
        $this->table = is_string($from) && ! str_contains($from, ' ') ? $from : '';
    }

    public function isUsable(): bool
    {
        return $this->table !== '' && $this->tableColumns($this->table) !== [];
    }

    public function model(): ?Model
    {
        return $this->model;
    }

    /**
     * @return 'sql'|'memory'|null null = unknown column, ignored by the grid
     */
    public function kind(string $key): ?string
    {
        if ($this->expression($key) !== null) {
            return self::SQL;
        }

        return $this->model !== null && $this->isMemorySafe($this->model, explode('.', $key)) ? self::MEMORY : null;
    }

    public function expression(string $key): ?GridExpression
    {
        if (! array_key_exists($key, $this->expressions)) {
            $this->expressions[$key] = $this->resolve($this->model, $this->table, explode('.', $key)) ?? false;
        }

        return $this->expressions[$key] ?: null;
    }

    /**
     * The relation paths to eager load before evaluating `$key` in memory.
     */
    public function eagerLoadFor(string $key): ?string
    {
        $segments = explode('.', $key);
        array_pop($segments);

        return $segments === [] ? null : implode('.', $segments);
    }

    /**
     * Text value of a memory column (null = empty cell).
     */
    public function memoryValue(Model $model, string $key): ?string
    {
        $current = $model;
        foreach (explode('.', $key) as $segment) {
            if (! $current instanceof Model) {
                return null;
            }
            $current = $current->getAttribute($segment);
        }

        return self::text($current);
    }

    public static function text(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => null,
        };
    }

    /**
     * @param  list<string>  $segments
     */
    private function resolve(?Model $model, string $table, array $segments): ?GridExpression
    {
        $first = $segments[0];

        if (count($segments) === 1) {
            if (in_array($first, $this->visibleColumns($model, $table), true)) {
                return new GridExpression($this->base->getGrammar()->wrap("{$table}.{$first}"), [], true);
            }
            $declared = $model !== null && method_exists($model, 'gridColumns') ? $model->gridColumns() : [];

            return isset($declared[$first]) && is_string($declared[$first])
                ? new GridExpression('('.$declared[$first].')')
                : null;
        }

        $relation = $model !== null ? $this->toOneRelation($model, $first) : null;
        if ($relation === null || $relation->getRelated()->getTable() === $table) {
            return null;
        }
        $related = $relation->getRelated();
        $inner = $this->resolve($related, $related->getTable(), array_slice($segments, 1));
        if ($inner === null) {
            return null;
        }

        $sub = $relation->getRelationExistenceQuery($related->newQuery(), $model->newQuery(), ['*']);
        $sub->getQuery()->columns = null;
        $sub->getQuery()->bindings['select'] = [];
        $sub->selectRaw($inner->sql, $inner->bindings)->limit(1);

        return new GridExpression('('.$sub->toSql().')', $sub->getBindings());
    }

    /**
     * @param  list<string>  $segments
     */
    private function isMemorySafe(Model $model, array $segments): bool
    {
        $first = array_shift($segments);
        if ($segments === []) {
            return in_array($first, $this->visibleColumns($model, $model->getTable()), true)
                || $model->hasGetMutator($first)
                || $model->hasAttributeGetMutator($first)
                || in_array($first, $model->getAppends(), true);
        }
        $relation = $this->toOneRelation($model, $first);

        return $relation !== null && $this->isMemorySafe($relation->getRelated(), $segments);
    }

    /**
     * A to-one relation declared with a Relation return type (never calls an arbitrary method).
     */
    private function toOneRelation(Model $model, string $name): ?Relation
    {
        if (! method_exists($model, $name)) {
            return null;
        }
        $method = new ReflectionMethod($model, $name);
        $type = $method->getReturnType();
        if (! $method->isPublic() || $method->getNumberOfRequiredParameters() > 0 || ! $type instanceof ReflectionNamedType
            || ! is_a($type->getName(), Relation::class, true)
            || ! (is_a($type->getName(), BelongsTo::class, true) || is_a($type->getName(), HasOne::class, true) || is_a($type->getName(), MorphOne::class, true))) {
            return null;
        }
        $relation = Relation::noConstraints(static fn (): mixed => $model->{$name}());

        return $relation instanceof Relation ? $relation : null;
    }

    /**
     * @return list<string>
     */
    private function visibleColumns(?Model $model, string $table): array
    {
        $columns = $this->tableColumns($table);

        return $model === null ? $columns : array_values(array_diff($columns, $model->getHidden()));
    }

    /**
     * @return list<string>
     */
    private function tableColumns(string $table): array
    {
        $key = $this->base->getConnection()->getName().'.'.$table;

        return self::$columnCache[$key] ??= $this->base->getConnection()->getSchemaBuilder()->getColumnListing($table);
    }
}
