<?php

declare(strict_types=1);

namespace Modules\Analytics\Semantic;

use InvalidArgumentException;

/**
 * A governed dataset of the semantic layer: a base table of the application (always filtered to the active tenant), the
 * joins it may reach, the fixed conditions that make its figures honest (posted documents only, no drafts) and the
 * allow-list of dimensions and measures. Nothing outside this declaration is reachable from a report.
 */
final readonly class Dataset
{
    /**
     * @var array<string, Dimension>
     */
    public array $dimensions;

    /**
     * @var array<string, Measure>
     */
    public array $measures;

    /**
     * @var array<string, DatasetJoin>
     */
    public array $joins;

    /**
     * @param  string  $module  key of config/permissions.php whose read access a user needs to use the dataset
     * @param  string  $table  base table; it has a tenant_id column
     * @param  list<DatasetJoin>  $joins
     * @param  list<string>  $conditions  fixed SQL conditions written by the developer
     * @param  list<Dimension>  $dimensions
     * @param  list<Measure>  $measures
     * @param  string|null  $periodDimension  key of the date dimension the report period applies to; null for datasets that describe today
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $module,
        public string $table,
        array $joins,
        public array $conditions,
        array $dimensions,
        array $measures,
        public ?string $periodDimension = null,
        public bool $softDeletes = true,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key) !== 1 || preg_match('/^[a-z][a-z0-9_]{0,60}$/', $table) !== 1) {
            throw new InvalidArgumentException("Invalid dataset [{$key}].");
        }

        $this->joins = array_column(array_map(static fn (DatasetJoin $join): array => [$join->table, $join], $joins), 1, 0);
        $this->dimensions = array_column(array_map(static fn (Dimension $dimension): array => [$dimension->key, $dimension], $dimensions), 1, 0);
        $this->measures = array_column(array_map(static fn (Measure $measure): array => [$measure->key, $measure], $measures), 1, 0);

        foreach ([...$dimensions, ...$measures] as $field) {
            if ($field->join !== null && ! isset($this->joins[$field->join])) {
                throw new InvalidArgumentException("[{$field->key}] needs the undeclared join [{$field->join}].");
            }
        }

        if (array_intersect_key($this->dimensions, $this->measures) !== []) {
            throw new InvalidArgumentException("Dataset [{$key}] reuses a key for a dimension and a measure.");
        }

        if ($periodDimension !== null && ($this->dimensions[$periodDimension] ?? null)?->type !== Dimension::TypeDate) {
            throw new InvalidArgumentException("Dataset [{$key}] has no date dimension [{$periodDimension}].");
        }
    }

    public function dimension(string $key): ?Dimension
    {
        return $this->dimensions[$key] ?? null;
    }

    public function measure(string $key): ?Measure
    {
        return $this->measures[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'module' => $this->module,
            'supports_period' => $this->periodDimension !== null,
            'period_dimension' => $this->periodDimension,
            'dimensions' => array_values(array_map(static fn (Dimension $dimension): array => $dimension->toArray(), $this->dimensions)),
            'measures' => array_values(array_map(static fn (Measure $measure): array => $measure->toArray(), $this->measures)),
        ];
    }
}
