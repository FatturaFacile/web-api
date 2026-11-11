<?php

declare(strict_types=1);

namespace Modules\Analytics\Semantic;

use App\Models\User;
use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Analytics\Support\ReportPeriod;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Compiles a validated {@see ReportSpec} into one query over a {@see Dataset} and runs it with hard limits.
 *
 * Guarantees, in this order of importance:
 * - the only SQL text in the query comes from the declaration of the dataset; user input reaches it as keys checked
 *   against the allow-list and as bound values;
 * - the base table and every joined table are restricted to the active tenant, whatever the filters say;
 * - the user must be able to read the module the dataset belongs to;
 * - a report never returns more than `analytics.reporting.max_rows` rows and a query running longer than
 *   `analytics.reporting.timeout_ms` is cancelled by the database.
 */
final readonly class ReportEngine
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        private SemanticCatalog $catalog,
        private TenantContext $tenantContext,
        private TenantClock $clock,
    ) {}

    /**
     * Hard cap of rows of a report or an export.
     */
    public function maxRows(): int
    {
        return max(1, (int) config('analytics.reporting.max_rows', 10000));
    }

    /**
     * The dataset of a key, if the user may read its module.
     *
     * @throws NotFoundHttpException for an unknown dataset
     * @throws AuthorizationException when the user cannot read the module of the dataset
     */
    public function datasetFor(string $key, User $user): Dataset
    {
        $dataset = $this->catalog->find($key) ?? throw new NotFoundHttpException('Unknown dataset.');

        if (! $this->catalog->canRead($dataset, $user)) {
            throw new AuthorizationException("Your role cannot read the {$dataset->module} data this dataset is drawn from.");
        }

        return $dataset;
    }

    /**
     * Validates the designer input against a dataset the user may read.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException when the input is outside the declaration of the dataset
     */
    public function parse(string $datasetKey, array $input, User $user, string $errorPrefix = ''): ReportSpec
    {
        $dataset = $this->datasetFor($datasetKey, $user);

        try {
            return ReportSpec::parse($input, $dataset, $this->maxRows(), (int) config('analytics.reporting.max_period_days', 1830));
        } catch (ValidationException $exception) {
            if ($errorPrefix === '') {
                throw $exception;
            }

            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $errors[$errorPrefix.$field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * One page of the report.
     *
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function run(ReportSpec $spec, User $user, int $page = 1, int $perPage = 50): array
    {
        $dataset = $this->datasetFor($spec->dataset, $user);
        $perPage = max(1, min($perPage, (int) config('analytics.reporting.max_per_page', 500)));
        $page = max(1, $page);

        return $this->guarded(function () use ($dataset, $spec, $page, $perPage): array {
            $periods = $this->periods($spec);
            $cap = min($spec->limit ?? $this->maxRows(), $this->maxRows());
            $base = $this->query($dataset, $spec, $periods['current']);
            $found = DB::query()->fromSub((clone $base)->reorder()->limit($cap + 1), 'r')->count();
            $total = min($found, $cap);
            $offset = ($page - 1) * $perPage;
            $take = max(0, min($perPage, $total - $offset));
            $rows = $take > 0 ? $this->fetch($dataset, $spec, $periods, $offset, $take, $this->previousIndex($dataset, $spec, $periods)) : [];

            return [
                'columns' => $this->columns($dataset, $spec),
                'rows' => $rows,
                'meta' => [
                    'dataset' => $dataset->key,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'pages' => max(1, (int) ceil($total / $perPage)),
                    'truncated' => $spec->limit === null && $found > $cap,
                    'row_limit' => $cap,
                    'period' => $periods['current'],
                    'comparison_period' => $periods['comparison'],
                ],
            ];
        });
    }

    /**
     * Opens an export: validates, counts the rows (under the row cap and the time limit) and returns a generator of rows
     * that reads the report in chunks, so a file can be written while it is produced instead of being built in memory.
     * Access, period and count errors surface here, before any byte is sent; each chunk runs under the statement timeout.
     *
     * @return array{columns: list<array<string, mixed>>, meta: array<string, mixed>, rows: Closure(): iterable<array<string, mixed>>}
     */
    public function openExport(ReportSpec $spec, User $user): array
    {
        $dataset = $this->datasetFor($spec->dataset, $user);
        $chunk = 1000;

        [$periods, $cap, $found, $previous] = $this->guarded(function () use ($dataset, $spec): array {
            $periods = $this->periods($spec);
            $cap = min($spec->limit ?? $this->maxRows(), $this->maxRows());
            $found = DB::query()->fromSub((clone $this->query($dataset, $spec, $periods['current']))->reorder()->limit($cap + 1), 'r')->count();

            return [$periods, $cap, $found, $this->previousIndex($dataset, $spec, $periods)];
        });
        $total = min($found, $cap);

        return [
            'columns' => $this->columns($dataset, $spec),
            'meta' => [
                'dataset' => $dataset->key,
                'row_count' => $total,
                'truncated' => $spec->limit === null && $found > $cap,
                'row_limit' => $cap,
                'period' => $periods['current'],
                'comparison_period' => $periods['comparison'],
            ],
            'rows' => function () use ($dataset, $spec, $periods, $previous, $total, $chunk): Generator {
                for ($offset = 0; $offset < $total; $offset += $chunk) {
                    $rows = $this->guarded(fn (): array => $this->fetch($dataset, $spec, $periods, $offset, min($chunk, $total - $offset), $previous));

                    yield from $rows;
                }
            },
        ];
    }

    /**
     * The dates a spec covers today: the period and, when asked, the one it is compared with.
     *
     * @return array{current: ?array{from: string, to: string}, comparison: ?array{from: string, to: string}}
     */
    public function periods(ReportSpec $spec): array
    {
        if ($spec->period === null) {
            return ['current' => null, 'comparison' => null];
        }

        $today = $this->clock->today();

        if (isset($spec->period['preset'])) {
            [$from, $to] = match ($spec->period['preset']) {
                'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
                'quarter_to_date' => [$today->startOfQuarter(), $today],
                'year_to_date' => [$today->startOfYear(), $today],
                'last_30_days' => [$today->subDays(29), $today],
                'last_12_months' => [$today->subYearNoOverflow()->addDay(), $today],
                default => [$today->startOfMonth(), $today],
            };
        } else {
            $from = CarbonImmutable::parse((string) $spec->period['from']);
            $to = CarbonImmutable::parse((string) $spec->period['to']);
        }

        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $comparison = match ($spec->compare) {
            ReportPeriod::ComparePreviousYear => [$from->subYearNoOverflow(), $to->subYearNoOverflow()],
            ReportPeriod::ComparePreviousPeriod => [$from->subDays((int) $from->diffInDays($to) + 1), $from->subDay()],
            default => null,
        };

        return [
            'current' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'comparison' => $comparison === null ? null : ['from' => $comparison[0]->toDateString(), 'to' => $comparison[1]->toDateString()],
        ];
    }

    /**
     * @param  array{from: string, to: string}|null  $period
     */
    private function query(Dataset $dataset, ReportSpec $spec, ?array $period, bool $having = true): Builder
    {
        $tenantId = $this->tenantContext->getTenantId();

        if ($tenantId === null) {
            throw new AuthorizationException('No active company.');
        }

        $query = DB::table($dataset->table)->where("{$dataset->table}.tenant_id", $tenantId);

        if ($dataset->softDeletes) {
            $query->whereNull("{$dataset->table}.deleted_at");
        }

        foreach ($dataset->joins as $join) {
            $this->join($query, $dataset, $join);
        }

        foreach ($dataset->conditions as $condition) {
            $query->whereRaw($condition);
        }

        foreach ($spec->filters as $filter) {
            $measure = $dataset->measure($filter['field']);

            if ($measure !== null) {
                if ($having) {
                    $this->having($query, $measure, $filter['operator'], $filter['value']);
                }

                continue;
            }

            $this->filter($query, $dataset->dimension($filter['field']) ?? throw new \LogicException('Unvalidated filter.'), $filter['operator'], $filter['value']);
        }

        if ($period !== null && $dataset->periodDimension !== null) {
            $query->whereRaw('('.$dataset->dimensions[$dataset->periodDimension]->sql.') BETWEEN ? AND ?', [$period['from'], $period['to']]);
        }

        foreach ($spec->dimensions as $selected) {
            $query->selectRaw($this->dimensionSql($dataset->dimensions[$selected['key']], $selected['granularity']).' AS "'.$selected['key'].'"');
        }

        foreach ($spec->measures as $key) {
            $query->selectRaw($dataset->measures[$key]->sql.' AS "'.$key.'"');
        }

        if ($spec->dimensions !== []) {
            $query->groupByRaw(implode(', ', range(1, count($spec->dimensions))));
        }

        foreach ($this->ordering($spec) as $order) {
            $query->orderByRaw('"'.$order['field'].'" '.($order['direction'] === 'desc' ? 'DESC' : 'ASC').' NULLS LAST');
        }

        return $query;
    }

    private function join(Builder $query, Dataset $dataset, DatasetJoin $join): void
    {
        $constraint = static function (JoinClause $clause) use ($dataset, $join): void {
            $clause->whereRaw($join->on)->whereRaw("{$join->table}.tenant_id = {$dataset->table}.tenant_id");

            if ($join->softDeletes) {
                $clause->whereRaw("{$join->table}.deleted_at IS NULL");
            }
        };

        $join->inner ? $query->join($join->table, $constraint) : $query->leftJoin($join->table, $constraint);
    }

    private function dimensionSql(Dimension $dimension, ?string $granularity): string
    {
        if ($dimension->type !== Dimension::TypeDate || $granularity === null) {
            return $dimension->sql;
        }

        if ($granularity === 'day') {
            return "to_char(({$dimension->sql})::date, 'YYYY-MM-DD')";
        }

        return "to_char(date_trunc('{$granularity}', ({$dimension->sql})::timestamp), 'YYYY-MM-DD')";
    }

    private function filter(Builder $query, Dimension $dimension, string $operator, mixed $value): void
    {
        $column = "({$dimension->sql})";

        match ($operator) {
            'eq' => $query->whereRaw("{$column} = ?", [$value]),
            'neq' => $query->whereRaw("{$column} IS DISTINCT FROM ?", [$value]),
            'gt' => $query->whereRaw("{$column} > ?", [$value]),
            'gte' => $query->whereRaw("{$column} >= ?", [$value]),
            'lt' => $query->whereRaw("{$column} < ?", [$value]),
            'lte' => $query->whereRaw("{$column} <= ?", [$value]),
            'between' => $query->whereRaw("{$column} BETWEEN ? AND ?", $value),
            'in' => $query->whereRaw("{$column} IN (".implode(', ', array_fill(0, count($value), '?')).')', $value),
            'not_in' => $query->whereRaw("({$column} IS NULL OR {$column} NOT IN (".implode(', ', array_fill(0, count($value), '?')).'))', $value),
            'contains' => $query->whereRaw("{$column} ILIKE ? ESCAPE '\\'", ['%'.$this->escapeLike((string) $value).'%']),
            'starts_with' => $query->whereRaw("{$column} ILIKE ? ESCAPE '\\'", [$this->escapeLike((string) $value).'%']),
            'is_null' => $query->whereRaw("{$column} IS NULL"),
            'not_null' => $query->whereRaw("{$column} IS NOT NULL"),
            default => throw new \LogicException("Unvalidated operator [{$operator}]."),
        };
    }

    /**
     * A condition on an aggregate. The expression is the one declared for the measure; the value is bound and cast.
     */
    private function having(Builder $query, Measure $measure, string $operator, mixed $value): void
    {
        $column = "({$measure->sql})";

        match ($operator) {
            'eq' => $query->havingRaw("{$column} = CAST(? AS numeric)", [$value]),
            'neq' => $query->havingRaw("{$column} IS DISTINCT FROM CAST(? AS numeric)", [$value]),
            'gt' => $query->havingRaw("{$column} > CAST(? AS numeric)", [$value]),
            'gte' => $query->havingRaw("{$column} >= CAST(? AS numeric)", [$value]),
            'lt' => $query->havingRaw("{$column} < CAST(? AS numeric)", [$value]),
            'lte' => $query->havingRaw("{$column} <= CAST(? AS numeric)", [$value]),
            'between' => $query->havingRaw("{$column} BETWEEN CAST(? AS numeric) AND CAST(? AS numeric)", $value),
            'is_null' => $query->havingRaw("{$column} IS NULL"),
            'not_null' => $query->havingRaw("{$column} IS NOT NULL"),
            default => throw new \LogicException("Unvalidated operator [{$operator}]."),
        };
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * The requested order, then a stable tie-break on every chosen dimension so pages never overlap. Without a request
     * the biggest first measure comes first.
     *
     * @return list<array{field: string, direction: string}>
     */
    private function ordering(ReportSpec $spec): array
    {
        $order = $spec->sort !== [] ? $spec->sort : [['field' => $spec->measures[0], 'direction' => 'desc']];

        foreach ($spec->dimensions as $dimension) {
            if (! in_array($dimension['key'], array_column($order, 'field'), true)) {
                $order[] = ['field' => $dimension['key'], 'direction' => 'asc'];
            }
        }

        return $order;
    }

    /**
     * @param  array{current: ?array{from: string, to: string}, comparison: ?array{from: string, to: string}}  $periods
     * @return list<array<string, mixed>>
     */
    private function fetch(Dataset $dataset, ReportSpec $spec, array $periods, int $offset, int $take, ?array $previous = null): array
    {
        $rows = $this->query($dataset, $spec, $periods['current'])->offset($offset)->limit($take)->get()
            ->map(fn (object $row): array => $this->normalise($dataset, $spec, (array) $row))
            ->all();

        if ($previous === null) {
            return $rows;
        }

        return array_map(function (array $row) use ($dataset, $spec, $previous): array {
            $before = $previous[$this->groupKey($spec, $row)] ?? null;

            foreach ($spec->measures as $key) {
                $scale = $dataset->measures[$key]->scale();
                $current = $row[$key];
                $past = $before[$key] ?? null;
                $row["{$key}__previous"] = $past;
                $row["{$key}__change"] = $current !== null && $past !== null ? bcsub((string) $current, (string) $past, $scale) : null;
                $base = $past === null ? null : ltrim((string) $past, '-');
                $row["{$key}__change_percent"] = $row["{$key}__change"] !== null && $base !== null && bccomp($base, '0', $scale) !== 0
                    ? bcround(bcdiv(bcmul((string) $row["{$key}__change"], '100', 4), $base, 4), 2)
                    : null;
            }

            return $row;
        }, $rows);
    }

    /**
     * The rows of the comparison period indexed by the group they belong to in the current period. Date buckets are
     * moved forward by the distance between the two periods (a year, or the length of the period) and cut again to their
     * granularity, so every previous bucket lands on the current bucket it is compared with. The filters on measures
     * are not applied here: they select the current rows, the previous value is whatever it was.
     *
     * @param  array{current: ?array{from: string, to: string}, comparison: ?array{from: string, to: string}}  $periods
     * @return array<string, array<string, mixed>>|null
     */
    private function previousIndex(Dataset $dataset, ReportSpec $spec, array $periods): ?array
    {
        if ($periods['comparison'] === null || $periods['current'] === null) {
            return null;
        }

        $length = (int) CarbonImmutable::parse($periods['current']['from'])->diffInDays(CarbonImmutable::parse($periods['current']['to'])) + 1;
        $index = [];

        foreach ($this->query($dataset, $spec, $periods['comparison'], having: false)->limit($this->maxRows())->get() as $row) {
            $normalised = $this->normalise($dataset, $spec, (array) $row);
            $index[$this->groupKey($spec, $normalised, $spec->compare, $length)] ??= $normalised;
        }

        return $index;
    }

    private function alignBucket(string $value, string $granularity, string $compare, int $length): string
    {
        $date = CarbonImmutable::parse($value);
        $moved = $compare === ReportPeriod::ComparePreviousYear ? $date->addYearNoOverflow() : $date->addDays($length);

        return match ($granularity) {
            'week' => $moved->startOfWeek()->toDateString(),
            'month' => $moved->startOfMonth()->toDateString(),
            'quarter' => $moved->startOfQuarter()->toDateString(),
            'year' => $moved->startOfYear()->toDateString(),
            default => $moved->toDateString(),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function groupKey(ReportSpec $spec, array $row, ?string $alignCompare = null, int $alignLength = 0): string
    {
        return json_encode(array_map(function (array $dimension) use ($row, $alignCompare, $alignLength): mixed {
            $value = $row[$dimension['key']] ?? null;

            return $alignCompare !== null && $dimension['granularity'] !== null && $value !== null
                ? $this->alignBucket((string) $value, $dimension['granularity'], $alignCompare, $alignLength)
                : $value;
        }, $spec->dimensions), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalise(Dataset $dataset, ReportSpec $spec, array $row): array
    {
        $out = [];

        foreach ($spec->dimensions as $dimension) {
            $value = $row[$dimension['key']] ?? null;
            $out[$dimension['key']] = $value === null ? null : (is_bool($value) ? $value : (string) $value);
        }

        foreach ($spec->measures as $key) {
            $measure = $dataset->measures[$key];
            $value = $row[$key] ?? null;
            $out[$key] = $value === null ? null : ($measure->scale() === 0 ? (int) $value : bcadd((string) $value, '0', $measure->scale()));
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function columns(Dataset $dataset, ReportSpec $spec): array
    {
        $columns = [];

        foreach ($spec->dimensions as $selected) {
            $dimension = $dataset->dimensions[$selected['key']];
            $columns[] = ['key' => $dimension->key, 'label' => $dimension->label, 'kind' => 'dimension', 'type' => $dimension->type, 'granularity' => $selected['granularity']];
        }

        foreach ($spec->measures as $key) {
            $measure = $dataset->measures[$key];
            $columns[] = ['key' => $key, 'label' => $measure->label, 'kind' => 'measure', 'unit' => $measure->unit];

            if ($spec->compare !== ReportPeriod::CompareNone) {
                $columns[] = ['key' => "{$key}__previous", 'label' => $measure->label.' (previous)', 'kind' => 'comparison', 'unit' => $measure->unit];
                $columns[] = ['key' => "{$key}__change", 'label' => $measure->label.' (change)', 'kind' => 'comparison', 'unit' => $measure->unit];
                $columns[] = ['key' => "{$key}__change_percent", 'label' => $measure->label.' (change %)', 'kind' => 'comparison', 'unit' => Measure::UnitPercent];
            }
        }

        return $columns;
    }

    /**
     * Runs the work in a transaction with the statement timeout of reports, and turns a cancelled query into a
     * validation error that tells the designer to narrow the report.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     *
     * @throws ValidationException when the database cancels the query for taking too long
     */
    private function guarded(Closure $work): mixed
    {
        $timeout = max(1, (int) config('analytics.reporting.timeout_ms', 15000));

        try {
            return DB::transaction(function () use ($work, $timeout): mixed {
                $postgres = DB::connection()->getDriverName() === 'pgsql';

                if ($postgres) {
                    DB::statement('SET LOCAL statement_timeout = '.$timeout);
                }

                $result = $work();

                if ($postgres) {
                    DB::statement('SET LOCAL statement_timeout = DEFAULT');
                }

                return $result;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '57014' || str_contains($exception->getMessage(), 'statement timeout')) {
                throw ValidationException::withMessages(['report' => 'The report took longer than '.($timeout / 1000).' seconds and was cancelled: narrow the period, add filters or group by fewer dimensions.']);
            }

            throw $exception;
        }
    }
}
