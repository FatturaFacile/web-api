<?php

declare(strict_types=1);

namespace Modules\Analytics\Semantic;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Analytics\Support\ReportPeriod;
use Throwable;

/**
 * The validated choices of a report designer on a dataset. Parsing is the only door from user input to a query: every
 * field name must be a key of the dataset (never a column, never SQL), every operator one of the allow-list of the
 * type, every value is coerced to the type of its dimension. Anything else is a validation error.
 *
 * Shape of the input and of {@see toArray()}:
 * `dimensions` list of `key` or `key:granularity`; `measures` list of keys; `filters` list of `{field, operator, value}`;
 * `sort` list of `{field, direction}`; `limit` top-N; `period` `{preset}` or `{from, to}`; `compare` none, previous_period
 * or previous_year.
 */
final readonly class ReportSpec
{
    public const int MaxDimensions = 4;

    public const int MaxMeasures = 8;

    public const int MaxFilters = 20;

    public const int MaxSort = 4;

    /**
     * Period presets, resolved on the calendar of the company when the report runs.
     *
     * @var list<string>
     */
    public const array Presets = ['month_to_date', 'last_month', 'quarter_to_date', 'year_to_date', 'last_30_days', 'last_12_months'];

    /**
     * @param  list<array{key: string, granularity: ?string}>  $dimensions
     * @param  list<string>  $measures
     * @param  list<array{field: string, operator: string, value: mixed}>  $filters
     * @param  list<array{field: string, direction: string}>  $sort
     * @param  array{preset?: string, from?: string, to?: string}|null  $period
     */
    public function __construct(
        public string $dataset,
        public array $dimensions,
        public array $measures,
        public array $filters = [],
        public array $sort = [],
        public ?int $limit = null,
        public ?array $period = null,
        public string $compare = ReportPeriod::CompareNone,
    ) {}

    /**
     * Validates the user input against the dataset.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException when anything is outside the declaration of the dataset
     */
    public static function parse(array $input, Dataset $dataset, int $maxRows, int $maxPeriodDays): self
    {
        $errors = [];

        $dimensions = [];
        $rawDimensions = $input['dimensions'] ?? [];

        if (! is_array($rawDimensions) || ! array_is_list($rawDimensions) || count($rawDimensions) > self::MaxDimensions) {
            $errors['dimensions'][] = 'Dimensions must be a list of at most '.self::MaxDimensions.' items.';
            $rawDimensions = [];
        }

        foreach ($rawDimensions as $index => $raw) {
            $parsed = self::parseDimension($raw, $dataset);

            if (is_string($parsed)) {
                $errors["dimensions.{$index}"][] = $parsed;
            } elseif (in_array($parsed['key'], array_column($dimensions, 'key'), true)) {
                $errors["dimensions.{$index}"][] = "The dimension [{$parsed['key']}] is repeated.";
            } else {
                $dimensions[] = $parsed;
            }
        }

        $measures = [];
        $rawMeasures = $input['measures'] ?? [];

        if (! is_array($rawMeasures) || ! array_is_list($rawMeasures) || $rawMeasures === [] || count($rawMeasures) > self::MaxMeasures) {
            $errors['measures'][] = 'Choose between 1 and '.self::MaxMeasures.' measures.';
            $rawMeasures = [];
        }

        foreach ($rawMeasures as $index => $raw) {
            if (! is_string($raw) || $dataset->measure($raw) === null) {
                $errors["measures.{$index}"][] = 'Unknown measure for this dataset.';
            } elseif (in_array($raw, $measures, true)) {
                $errors["measures.{$index}"][] = "The measure [{$raw}] is repeated.";
            } else {
                $measures[] = $raw;
            }
        }

        $filters = self::parseFilters($input['filters'] ?? [], $dataset, $errors);
        $sort = self::parseSort($input['sort'] ?? [], $dimensions, $measures, $errors);
        $limit = self::parseLimit($input['limit'] ?? null, $maxRows, $errors);
        $period = self::parsePeriod($input['period'] ?? null, $dataset, $maxPeriodDays, $errors);
        $compare = $input['compare'] ?? ReportPeriod::CompareNone;

        if (! in_array($compare, [ReportPeriod::CompareNone, ReportPeriod::ComparePreviousPeriod, ReportPeriod::ComparePreviousYear], true)) {
            $errors['compare'][] = 'Unknown comparison.';
            $compare = ReportPeriod::CompareNone;
        } elseif ($compare !== ReportPeriod::CompareNone) {
            if ($dataset->periodDimension === null || $period === null) {
                $errors['compare'][] = 'A comparison needs a dataset with a period and a period.';
            }

            foreach ($dimensions as $dimension) {
                if ($dataset->dimension($dimension['key'])?->type === Dimension::TypeDate) {
                    $errors['compare'][] = 'A comparison cannot be combined with grouping by a date: the groups of the two periods would not match.';

                    break;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new self($dataset->key, $dimensions, $measures, $filters, $sort, $limit, $period, (string) $compare);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dimensions' => array_map(static fn (array $dimension): string => $dimension['granularity'] === null ? $dimension['key'] : "{$dimension['key']}:{$dimension['granularity']}", $this->dimensions),
            'measures' => $this->measures,
            'filters' => $this->filters,
            'sort' => $this->sort,
            'limit' => $this->limit,
            'period' => $this->period,
            'compare' => $this->compare,
        ];
    }

    /**
     * @return array{key: string, granularity: ?string}|string the dimension, or the reason it was refused
     */
    private static function parseDimension(mixed $raw, Dataset $dataset): array|string
    {
        if (! is_string($raw) || preg_match('/^([a-z][a-z0-9_]*)(?::([a-z]+))?$/', $raw, $matches) !== 1) {
            return 'A dimension is a key, optionally followed by :granularity.';
        }

        $dimension = $dataset->dimension($matches[1]);

        if ($dimension === null) {
            return 'Unknown dimension for this dataset.';
        }

        $granularity = $matches[2] ?? null;

        if ($granularity !== null && ($dimension->type !== Dimension::TypeDate || ! in_array($granularity, Dimension::Granularities, true))) {
            return 'Unknown granularity for this dimension.';
        }

        return ['key' => $dimension->key, 'granularity' => $dimension->type === Dimension::TypeDate ? ($granularity ?? 'day') : null];
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<array{field: string, operator: string, value: mixed}>
     */
    private static function parseFilters(mixed $raw, Dataset $dataset, array &$errors): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) > self::MaxFilters) {
            $errors['filters'][] = 'Filters must be a list of at most '.self::MaxFilters.' items.';

            return [];
        }

        $filters = [];

        foreach ($raw as $index => $filter) {
            $dimension = is_array($filter) && is_string($filter['field'] ?? null) ? $dataset->dimension($filter['field']) : null;

            if ($dimension === null) {
                $errors["filters.{$index}.field"][] = 'Unknown dimension for this dataset.';

                continue;
            }

            $operator = $filter['operator'] ?? null;

            if (! is_string($operator) || ! in_array($operator, $dimension->operators(), true)) {
                $errors["filters.{$index}.operator"][] = 'Operator not allowed for a '.$dimension->type.' dimension.';

                continue;
            }

            $value = self::coerceValue($dimension, $operator, $filter['value'] ?? null);

            if ($value === false) {
                $errors["filters.{$index}.value"][] = 'Invalid value for a '.$dimension->type.' dimension and the operator '.$operator.'.';

                continue;
            }

            $filters[] = ['field' => $dimension->key, 'operator' => $operator, 'value' => $value];
        }

        return $filters;
    }

    /**
     * The value in the form the query binds, null for operators without a value, false when it is not acceptable.
     */
    private static function coerceValue(Dimension $dimension, string $operator, mixed $value): mixed
    {
        if (in_array($operator, ['is_null', 'not_null'], true)) {
            return null;
        }

        if (in_array($operator, ['in', 'not_in'], true)) {
            if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > 100) {
                return false;
            }

            $values = array_map(static fn (mixed $item): mixed => self::coerceScalar($dimension, $item), $value);

            return in_array(false, $values, true) ? false : $values;
        }

        if ($operator === 'between') {
            if (! is_array($value) || ! array_is_list($value) || count($value) !== 2) {
                return false;
            }

            $values = array_map(static fn (mixed $item): mixed => self::coerceScalar($dimension, $item), $value);

            return in_array(false, $values, true) ? false : $values;
        }

        return self::coerceScalar($dimension, $value);
    }

    private static function coerceScalar(Dimension $dimension, mixed $value): string|bool
    {
        if (! is_scalar($value) || is_bool($value) && $dimension->type !== Dimension::TypeBoolean) {
            return false;
        }

        return match ($dimension->type) {
            Dimension::TypeNumber => is_numeric($value) && preg_match('/^-?\d{1,18}(\.\d{1,6})?$/', (string) $value) === 1 ? (string) $value : false,
            Dimension::TypeDate => self::isDate((string) $value) ? (string) $value : false,
            Dimension::TypeBoolean => match (true) {
                $value === true || $value === 1 || $value === '1' || $value === 'true' => 'true',
                $value === false || $value === 0 || $value === '0' || $value === 'false' => 'false',
                default => false,
            },
            default => mb_strlen((string) $value) <= 200 && ! str_contains((string) $value, "\0") ? (string) $value : false,
        };
    }

    private static function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)->toDateString() === $value;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<array{key: string, granularity: ?string}>  $dimensions
     * @param  list<string>  $measures
     * @param  array<string, list<string>>  $errors
     * @return list<array{field: string, direction: string}>
     */
    private static function parseSort(mixed $raw, array $dimensions, array $measures, array &$errors): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) > self::MaxSort) {
            $errors['sort'][] = 'Sort must be a list of at most '.self::MaxSort.' items.';

            return [];
        }

        $selectable = [...array_column($dimensions, 'key'), ...$measures];
        $sort = [];

        foreach ($raw as $index => $item) {
            $field = is_array($item) ? ($item['field'] ?? null) : null;
            $direction = is_array($item) ? strtolower((string) ($item['direction'] ?? 'asc')) : 'asc';

            if (! is_string($field) || ! in_array($field, $selectable, true)) {
                $errors["sort.{$index}.field"][] = 'Sort only by a chosen dimension or measure.';
            } elseif (! in_array($direction, ['asc', 'desc'], true)) {
                $errors["sort.{$index}.direction"][] = 'Direction is asc or desc.';
            } elseif (in_array($field, array_column($sort, 'field'), true)) {
                $errors["sort.{$index}.field"][] = 'The field is already used to sort.';
            } else {
                $sort[] = ['field' => $field, 'direction' => $direction];
            }
        }

        return $sort;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private static function parseLimit(mixed $raw, int $maxRows, array &$errors): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_int($raw) && ! (is_string($raw) && ctype_digit($raw)) || (int) $raw < 1 || (int) $raw > $maxRows) {
            $errors['limit'][] = "The top-N limit is a whole number between 1 and {$maxRows}.";

            return null;
        }

        return (int) $raw;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array{preset?: string, from?: string, to?: string}|null
     */
    private static function parsePeriod(mixed $raw, Dataset $dataset, int $maxDays, array &$errors): ?array
    {
        if ($raw === null || $raw === []) {
            return null;
        }

        if ($dataset->periodDimension === null) {
            $errors['period'][] = 'This dataset describes today and has no period.';

            return null;
        }

        if (! is_array($raw)) {
            $errors['period'][] = 'The period is an object with a preset, or with from and to.';

            return null;
        }

        if (isset($raw['preset'])) {
            if (! is_string($raw['preset']) || ! in_array($raw['preset'], self::Presets, true)) {
                $errors['period.preset'][] = 'Unknown preset.';

                return null;
            }

            return ['preset' => $raw['preset']];
        }

        $from = $raw['from'] ?? null;
        $to = $raw['to'] ?? null;

        if (! is_string($from) || ! is_string($to) || ! self::isDate($from) || ! self::isDate($to)) {
            $errors['period'][] = 'from and to are dates (Y-m-d).';

            return null;
        }

        if ($from > $to) {
            $errors['period'][] = 'The start of the period is after its end.';

            return null;
        }

        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > $maxDays) {
            $errors['period'][] = "The period cannot be longer than {$maxDays} days.";

            return null;
        }

        return ['from' => $from, 'to' => $to];
    }
}
