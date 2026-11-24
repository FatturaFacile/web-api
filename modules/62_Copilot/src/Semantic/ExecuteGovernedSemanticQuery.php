<?php

declare(strict_types=1);

namespace Modules\Copilot\Semantic;

use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Services\ModuleAccessGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Modules\Copilot\DTOs\ToolContext;
use Modules\Copilot\DTOs\ToolResult;
use Throwable;

/**
 * Runs a structured Semantic Query (entity, metrics, dimensions, filters, ordering) as a parameterized, tenant-scoped query.
 * The model never writes SQL: it names entities and fields from the closed registry, every value is bound as a parameter and
 * the fields the caller may not see (salaries without payroll access) are refused or redacted. The result carries a chart
 * hint and clickable entity citations. Aggregation runs over at most `copilot.limits.semantic_max_rows` rows so that month
 * and year buckets behave the same on SQLite and PostgreSQL.
 */
final readonly class ExecuteGovernedSemanticQuery
{
    private const array Functions = ['count', 'sum', 'avg', 'min', 'max'];

    private const array Operators = ['eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];

    private const array Charts = ['bar', 'line', 'pie', 'kpi_card', 'table'];

    private const string Redacted = '[REDACTED]';

    public function __construct(
        private SemanticEntityRegistry $entities,
        private ModuleAccessGate $gate,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws SemanticQueryException
     * @throws AuthorizationException
     */
    public function execute(ToolContext $context, array $query): ToolResult
    {
        $entity = $this->entities->find((string) ($query['entity'] ?? ''))
            ?? throw new SemanticQueryException('Unknown entity. Use one of: '.implode(', ', array_keys($this->entities->all())).'.');

        if (! $this->gate->allows($context->user, $context->tenantId, $entity->module, 'read')) {
            throw new AuthorizationException("The user cannot read {$entity->key}.");
        }

        $masked = $this->maskedFields($context, $entity);
        $metrics = $this->metrics($entity, $masked, (array) ($query['metrics'] ?? []));
        $dimensions = $this->dimensions($entity, $masked, (array) ($query['dimensions'] ?? []));
        $limit = max(1, min((int) ($query['limit'] ?? 50), (int) config('copilot.limits.semantic_result_rows', 200)));

        if ($dimensions !== [] && $metrics === []) {
            $metrics = [['fn' => 'count', 'field' => null, 'alias' => 'count']];
        }

        $builder = $this->scoped($entity, $context->tenantId);
        $this->applyFilters($builder, $entity, $masked, (array) ($query['filters'] ?? []));

        $result = $metrics === []
            ? $this->listRows($builder, $entity, $masked, $query, $limit)
            : $this->aggregate($builder, $entity, $metrics, $dimensions, (array) ($query['order_by'] ?? []), $limit);

        $result['data'] = ['entity' => $entity->key] + $result['data'] + ['masked_fields' => array_keys($masked)];

        return new ToolResult(
            $result['data'],
            $this->visualization($entity, $result, $query['chart'] ?? null),
            $result['citations'],
        );
    }

    /**
     * @return array<string, SemanticField>
     */
    private function maskedFields(ToolContext $context, SemanticEntity $entity): array
    {
        $masked = [];

        foreach ($entity->fields as $name => $field) {
            if ($field->sensitiveModule !== null && ! $this->gate->allows($context->user, $context->tenantId, $field->sensitiveModule, 'read')) {
                $masked[$name] = $field;
            }
        }

        return $masked;
    }

    private function scoped(SemanticEntity $entity, string $tenantId): Builder
    {
        $class = $entity->modelClass;

        return $class::query()->toBase()->where($entity->table().'.tenant_id', $tenantId);
    }

    /**
     * @param  array<string, SemanticField>  $masked
     */
    private function field(SemanticEntity $entity, array $masked, string $name): SemanticField
    {
        if (! isset($entity->fields[$name])) {
            throw new SemanticQueryException("Unknown field [{$name}] of {$entity->key}. Use one of: ".implode(', ', array_keys($entity->fields)).'.');
        }

        if (isset($masked[$name])) {
            throw new SemanticQueryException("The field [{$name}] is restricted for this user.");
        }

        return $entity->fields[$name];
    }

    /**
     * @param  array<string, SemanticField>  $masked
     * @param  array<int, mixed>  $raw
     * @return list<array{fn: string, field: ?string, alias: string}>
     */
    private function metrics(SemanticEntity $entity, array $masked, array $raw): array
    {
        $metrics = [];

        foreach ($raw as $metric) {
            $metric = (array) $metric;
            $function = (string) ($metric['fn'] ?? '');
            $field = isset($metric['field']) ? (string) $metric['field'] : null;

            if (! in_array($function, self::Functions, true)) {
                throw new SemanticQueryException('Unknown metric function. Use one of: '.implode(', ', self::Functions).'.');
            }

            if ($field === null && $function !== 'count') {
                throw new SemanticQueryException("The metric [{$function}] needs a field.");
            }

            if ($field !== null) {
                $type = $this->field($entity, $masked, $field)->type;

                if (in_array($function, ['sum', 'avg'], true) && $type !== SemanticField::Number) {
                    throw new SemanticQueryException("The metric [{$function}] needs a number field, [{$field}] is {$type}.");
                }

                if (in_array($function, ['min', 'max'], true) && ! in_array($type, [SemanticField::Number, SemanticField::Date], true)) {
                    throw new SemanticQueryException("The metric [{$function}] needs a number or date field, [{$field}] is {$type}.");
                }
            }

            $alias = (string) ($metric['alias'] ?? ($field === null ? $function : "{$function}_{$field}"));

            if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $alias) !== 1) {
                throw new SemanticQueryException('A metric alias is lowercase letters, digits and underscores.');
            }

            $metrics[] = ['fn' => $function, 'field' => $field, 'alias' => $alias];
        }

        if (count(array_unique(array_column($metrics, 'alias'))) !== count($metrics)) {
            throw new SemanticQueryException('Metric aliases must be unique.');
        }

        return $metrics;
    }

    /**
     * @param  array<string, SemanticField>  $masked
     * @param  array<int, mixed>  $raw
     * @return list<array{field: string, bucket: ?string, name: string}>
     */
    private function dimensions(SemanticEntity $entity, array $masked, array $raw): array
    {
        $dimensions = [];

        foreach ($raw as $dimension) {
            $dimension = (string) $dimension;
            $bucket = null;
            $field = $dimension;

            if (str_contains($dimension, ':')) {
                [$bucket, $field] = explode(':', $dimension, 2);

                if (! in_array($bucket, ['month', 'year'], true)) {
                    throw new SemanticQueryException('A dimension bucket is month or year, written month:<date field>.');
                }
            }

            $type = $this->field($entity, $masked, $field)->type;

            if ($bucket !== null && $type !== SemanticField::Date) {
                throw new SemanticQueryException("The bucket [{$bucket}] needs a date field, [{$field}] is {$type}.");
            }

            $dimensions[] = ['field' => $field, 'bucket' => $bucket, 'name' => $bucket === null ? $field : "{$bucket}_{$field}"];
        }

        return $dimensions;
    }

    /**
     * @param  array<string, SemanticField>  $masked
     * @param  array<int, mixed>  $filters
     */
    private function applyFilters(Builder $builder, SemanticEntity $entity, array $masked, array $filters): void
    {
        $table = $entity->table();

        foreach ($filters as $filter) {
            $filter = (array) $filter;
            $name = (string) ($filter['field'] ?? '');
            $type = $this->field($entity, $masked, $name)->type;
            $operator = (string) ($filter['op'] ?? 'eq');
            $value = $filter['value'] ?? null;
            $column = "{$table}.{$name}";

            if ($operator === 'in') {
                $builder->whereIn($column, array_map(fn (mixed $item): string => $this->scalar($type, $item), (array) $value));
            } elseif ($operator === 'between') {
                $bounds = array_values((array) $value);

                if (count($bounds) !== 2) {
                    throw new SemanticQueryException('The filter [between] needs exactly two values.');
                }

                $builder->whereBetween($column, [$this->scalar($type, $bounds[0]), $this->scalar($type, $bounds[1])]);
            } elseif ($operator === 'contains') {
                $needle = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower((string) $value));
                $builder->whereRaw('LOWER('.$builder->getGrammar()->wrap($column).") LIKE ? ESCAPE '\\'", ['%'.$needle.'%']);
            } elseif (isset(self::Operators[$operator])) {
                $builder->where($column, self::Operators[$operator], $this->scalar($type, $value));
            } else {
                throw new SemanticQueryException('Unknown filter operator. Use one of: '.implode(', ', [...array_keys(self::Operators), 'in', 'between', 'contains']).'.');
            }
        }
    }

    private function scalar(string $type, mixed $value): string
    {
        if (! is_scalar($value)) {
            throw new SemanticQueryException('A filter value is a single text, number or date.');
        }

        if ($type === SemanticField::Number) {
            if (! is_numeric($value)) {
                throw new SemanticQueryException('A number field is compared with a number.');
            }

            return (string) $value;
        }

        if ($type === SemanticField::Date) {
            try {
                return CarbonImmutable::parse((string) $value)->toDateString();
            } catch (Throwable) {
                throw new SemanticQueryException('A date field is compared with a date written YYYY-MM-DD.');
            }
        }

        return (string) $value;
    }

    /**
     * @param  array<string, SemanticField>  $masked
     * @param  array<string, mixed>  $query
     * @return array{data: array<string, mixed>, columns: list<string>, citations: list<array<string, mixed>>, mode: string, dimensions: list<string>, metrics: list<string>}
     */
    private function listRows(Builder $builder, SemanticEntity $entity, array $masked, array $query, int $limit): array
    {
        $selected = array_values(array_map('strval', (array) ($query['select'] ?? [])));
        $redacted = [];

        if ($selected === []) {
            $selected = array_values(array_diff(array_keys($entity->fields), array_keys($masked)));
        }

        foreach ($selected as $name) {
            if (! isset($entity->fields[$name])) {
                throw new SemanticQueryException("Unknown field [{$name}] of {$entity->key}.");
            }

            if (isset($masked[$name])) {
                $redacted[] = $name;
            }
        }

        $readable = array_values(array_diff($selected, $redacted));
        $table = $entity->table();
        $order = (array) ($query['order_by'] ?? []);
        $orderField = isset($order['field']) ? (string) $order['field'] : null;

        if ($orderField !== null) {
            $this->field($entity, $masked, $orderField);
            $builder->orderBy("{$table}.{$orderField}", ($order['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc');
        } else {
            $builder->orderBy("{$table}.id");
        }

        $columns = array_values(array_unique(['id', $entity->labelColumn, ...$readable]));
        $records = $builder->limit($limit)->get(array_map(static fn (string $column): string => "{$table}.{$column}", $columns));

        $rows = [];
        $citations = [];

        foreach ($records as $record) {
            $row = [];

            foreach ($selected as $name) {
                $row[$name] = in_array($name, $redacted, true) ? self::Redacted : $record->{$name};
            }

            $rows[] = $row;
            $citations[] = $this->citation($entity, (string) $record->id, (string) $record->{$entity->labelColumn});
        }

        return [
            'data' => ['mode' => 'list', 'rows' => $rows, 'row_count' => count($rows), 'truncated' => count($rows) === $limit],
            'columns' => $selected,
            'citations' => $citations,
            'mode' => 'list',
            'dimensions' => [],
            'metrics' => [],
        ];
    }

    /**
     * @param  list<array{fn: string, field: ?string, alias: string}>  $metrics
     * @param  list<array{field: string, bucket: ?string, name: string}>  $dimensions
     * @param  array<string, mixed>  $order
     * @return array{data: array<string, mixed>, columns: list<string>, citations: list<array<string, mixed>>, mode: string, dimensions: list<string>, metrics: list<string>}
     */
    private function aggregate(Builder $builder, SemanticEntity $entity, array $metrics, array $dimensions, array $order, int $limit): array
    {
        $table = $entity->table();
        $names = [...array_column($dimensions, 'name'), ...array_column($metrics, 'alias')];

        if (count(array_unique($names)) !== count($names)) {
            throw new SemanticQueryException('A dimension and a metric alias share a name.');
        }

        $orderField = isset($order['field']) ? (string) $order['field'] : null;

        if ($orderField !== null && ! in_array($orderField, $names, true)) {
            throw new SemanticQueryException('Order by a dimension name or a metric alias.');
        }

        $needed = array_values(array_unique(array_filter([...array_column($dimensions, 'field'), ...array_column($metrics, 'field')])));
        $maxRows = (int) config('copilot.limits.semantic_max_rows', 20000);
        $records = $builder->limit($maxRows + 1)->get($needed === [] ? ["{$table}.id"] : array_map(static fn (string $column): string => "{$table}.{$column}", $needed));
        $truncated = $records->count() > $maxRows;

        $groups = [];

        foreach ($records->take($maxRows) as $record) {
            $values = [];

            foreach ($dimensions as $dimension) {
                $value = $record->{$dimension['field']};
                $values[$dimension['name']] = $value === null ? null : match ($dimension['bucket']) {
                    'month' => substr((string) $value, 0, 7),
                    'year' => substr((string) $value, 0, 4),
                    default => $value,
                };
            }

            $key = (string) json_encode($values);
            $groups[$key] ??= ['values' => $values, 'rows' => []];
            $groups[$key]['rows'][] = $record;
        }

        if ($dimensions === []) {
            $groups = ['[]' => ['values' => [], 'rows' => $records->take($maxRows)->all()]];
        }

        $rows = [];

        foreach ($groups as $group) {
            $row = $group['values'];

            foreach ($metrics as $metric) {
                $row[$metric['alias']] = $this->reduce($metric, $group['rows']);
            }

            $rows[] = $row;
        }

        $sortBy = $orderField ?? ($dimensions !== [] && $dimensions[0]['bucket'] !== null ? $dimensions[0]['name'] : ($dimensions !== [] ? $metrics[0]['alias'] : null));
        $direction = $orderField !== null ? (($order['direction'] ?? 'asc') === 'desc' ? -1 : 1) : ($sortBy !== null && $sortBy === ($dimensions[0]['name'] ?? null) ? 1 : -1);

        if ($sortBy !== null) {
            usort($rows, static function (array $a, array $b) use ($sortBy, $direction): int {
                $left = $a[$sortBy];
                $right = $b[$sortBy];

                return $direction * (is_numeric($left) && is_numeric($right) ? bccomp((string) $left, (string) $right, 6) : strcmp((string) $left, (string) $right));
            });
        }

        $rows = array_slice($rows, 0, $limit);

        return [
            'data' => ['mode' => 'aggregate', 'rows' => $rows, 'row_count' => count($rows), 'truncated' => $truncated],
            'columns' => $names,
            'citations' => [],
            'mode' => 'aggregate',
            'dimensions' => array_column($dimensions, 'name'),
            'metrics' => array_column($metrics, 'alias'),
        ];
    }

    /**
     * @param  array{fn: string, field: ?string, alias: string}  $metric
     * @param  array<int, object>  $records
     */
    private function reduce(array $metric, array $records): int|string|null
    {
        $field = $metric['field'];
        $values = $field === null
            ? $records
            : array_values(array_filter(array_map(static fn (object $record): mixed => $record->{$field}, $records), static fn (mixed $value): bool => $value !== null));

        if ($metric['fn'] === 'count') {
            return count($values);
        }

        if ($values === []) {
            return null;
        }

        if ($metric['fn'] === 'sum' || $metric['fn'] === 'avg') {
            $sum = '0';

            foreach ($values as $value) {
                $sum = bcadd($sum, (string) $value, 6);
            }

            return bcround($metric['fn'] === 'sum' ? $sum : bcdiv($sum, (string) count($values), 6), 2);
        }

        $best = (string) $values[0];

        foreach ($values as $value) {
            $value = (string) $value;
            $comparison = is_numeric($value) && is_numeric($best) ? bccomp($value, $best, 6) : strcmp($value, $best);

            if (($metric['fn'] === 'min' && $comparison < 0) || ($metric['fn'] === 'max' && $comparison > 0)) {
                $best = $value;
            }
        }

        return is_numeric($best) ? bcround($best, 2) : $best;
    }

    /**
     * @return array<string, mixed>
     */
    private function citation(SemanticEntity $entity, string $id, string $label): array
    {
        return [
            'type' => 'entity',
            'label' => '#'.$entity->citationType.'-'.preg_replace('/\s+/', '-', trim($label)),
            'entity_type' => $entity->key,
            'entity_id' => $id,
            'route' => str_replace('{id}', $id, $entity->route),
        ];
    }

    /**
     * The chart hint for the client: the requested chart when it fits the shape of the result, otherwise the natural one.
     *
     * @param  array{data: array<string, mixed>, columns: list<string>, mode: string, dimensions: list<string>, metrics: list<string>}  $result
     * @return array<string, mixed>
     */
    private function visualization(SemanticEntity $entity, array $result, mixed $requested): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $result['data']['rows'];
        $dimensions = $result['dimensions'];
        $metrics = $result['metrics'];

        $type = 'table';

        if ($result['mode'] === 'aggregate') {
            $isTime = $dimensions !== [] && (str_starts_with($dimensions[0], 'month_') || str_starts_with($dimensions[0], 'year_'));
            $natural = match (true) {
                $dimensions === [] && count($metrics) === 1 => 'kpi_card',
                $dimensions === [] => 'table',
                $isTime => 'line',
                default => 'bar',
            };

            $fits = static fn (string $chart): bool => match ($chart) {
                'kpi_card' => $dimensions === [] && count($metrics) === 1,
                'pie' => count($dimensions) === 1 && count($metrics) === 1,
                'bar', 'line' => $dimensions !== [],
                default => true,
            };

            $type = is_string($requested) && in_array($requested, self::Charts, true) && $fits($requested) ? $requested : $natural;
        }

        $visualization = ['type' => $type, 'title' => $entity->description, 'columns' => $result['columns'], 'rows' => $rows];

        if (in_array($type, ['bar', 'line', 'pie'], true)) {
            $visualization['x'] = $dimensions[0];
            $visualization['labels'] = array_map(static fn (array $row): string => (string) ($row[$dimensions[0]] ?? ''), $rows);
            $visualization['datasets'] = array_map(static fn (string $metric): array => [
                'name' => $metric,
                'data' => array_map(static fn (array $row): float => (float) ($row[$metric] ?? 0), $rows),
            ], $metrics);
        }

        if ($type === 'kpi_card') {
            $visualization['metric'] = $metrics[0];
            $visualization['value'] = $rows[0][$metrics[0]] ?? null;
        }

        return $visualization;
    }
}
