<?php

declare(strict_types=1);

namespace Modules\Observability\Profiler;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Missing index advisor: reads a PostgreSQL EXPLAIN (FORMAT JSON) plan and proposes `CREATE INDEX CONCURRENTLY` statements for expensive
 * sequential scans (filter predicates), join keys on a scanned table and sort keys that a scan feeds. Tenant-owned tables get tenant_id as
 * leading column. The proposals are suggestions for a DBA, nothing is ever executed.
 */
final class IndexAdvisor
{
    private const int MAX_SUGGESTIONS = 5;

    private const string IDENTIFIER = '/^[a-z_][a-z0-9_]*$/';

    /** @var list<string> */
    private const array KEYWORDS = ['and', 'or', 'not', 'null', 'true', 'false', 'any', 'all', 'is', 'in', 'like', 'ilike', 'between', 'distinct', 'from', 'text', 'varchar', 'uuid', 'date', 'timestamp', 'numeric', 'integer', 'bigint', 'boolean'];

    public function __construct(private readonly float $minTotalCost = 1000.0) {}

    /**
     * @param  array<array-key, mixed>  $plan  The first element of the EXPLAIN FORMAT JSON document (it holds the "Plan" key).
     * @return list<array{table: string, columns: list<string>, sql: string, reason: string}>
     */
    public function advise(array $plan, ?string $connection = null): array
    {
        $root = $plan['Plan'] ?? null;

        if (! is_array($root)) {
            return [];
        }

        $aliases = [];
        $scans = [];
        $this->collect($root, $aliases, $scans);

        $suggestions = [];

        foreach ($scans as $scan) {
            if ($scan['cost'] < $this->minTotalCost) {
                continue;
            }

            $columns = $this->filterColumns($scan['filter']);

            if ($columns !== []) {
                $suggestions[] = $this->suggestion($scan['table'], $columns, 'seq_scan_filter', $connection);
            }
        }

        $this->joinSuggestions($root, $aliases, $scans, $suggestions, $connection);
        $this->sortSuggestions($root, $aliases, $scans, $suggestions, $connection);

        $unique = [];

        foreach ($suggestions as $suggestion) {
            if ($suggestion !== null) {
                $unique[$suggestion['table'].'|'.implode(',', $suggestion['columns'])] ??= $suggestion;
            }
        }

        return array_slice(array_values($unique), 0, self::MAX_SUGGESTIONS);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  array<string, string>  $aliases
     * @param  array<string, array{table: string, cost: float, filter: string}>  $scans
     */
    private function collect(array $node, array &$aliases, array &$scans): void
    {
        $table = is_string($node['Relation Name'] ?? null) ? $node['Relation Name'] : null;

        if ($table !== null) {
            $alias = is_string($node['Alias'] ?? null) ? $node['Alias'] : $table;
            $aliases[$alias] = $table;

            if (($node['Node Type'] ?? null) === 'Seq Scan') {
                $scans[$alias] = ['table' => $table, 'cost' => (float) ($node['Total Cost'] ?? 0), 'filter' => (string) ($node['Filter'] ?? '')];
            }
        }

        foreach ((array) ($node['Plans'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collect($child, $aliases, $scans);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  array<string, string>  $aliases
     * @param  array<string, array{table: string, cost: float, filter: string}>  $scans
     * @param  list<array{table: string, columns: list<string>, sql: string, reason: string}|null>  $suggestions
     */
    private function joinSuggestions(array $node, array $aliases, array $scans, array &$suggestions, ?string $connection): void
    {
        foreach (['Hash Cond', 'Merge Cond', 'Join Filter'] as $key) {
            if (! is_string($node[$key] ?? null)) {
                continue;
            }

            if (preg_match_all('/\b(\w+)\.(\w+)\s*=\s*(\w+)\.(\w+)\b/', $node[$key], $matches, PREG_SET_ORDER) < 1) {
                continue;
            }

            foreach ($matches as [, $leftAlias, $leftColumn, $rightAlias, $rightColumn]) {
                foreach ([[$leftAlias, $leftColumn], [$rightAlias, $rightColumn]] as [$alias, $column]) {
                    if (isset($scans[$alias], $aliases[$alias]) && $scans[$alias]['cost'] >= $this->minTotalCost) {
                        $suggestions[] = $this->suggestion($aliases[$alias], [$column], 'join_key', $connection);
                    }
                }
            }
        }

        foreach ((array) ($node['Plans'] ?? []) as $child) {
            if (is_array($child)) {
                $this->joinSuggestions($child, $aliases, $scans, $suggestions, $connection);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  array<string, string>  $aliases
     * @param  array<string, array{table: string, cost: float, filter: string}>  $scans
     * @param  list<array{table: string, columns: list<string>, sql: string, reason: string}|null>  $suggestions
     */
    private function sortSuggestions(array $node, array $aliases, array $scans, array &$suggestions, ?string $connection): void
    {
        if (($node['Node Type'] ?? null) === 'Sort' && is_array($node['Sort Key'] ?? null)) {
            $child = $node['Plans'][0] ?? null;
            $alias = is_array($child) && is_string($child['Alias'] ?? null) ? $child['Alias'] : (is_array($child) && is_string($child['Relation Name'] ?? null) ? $child['Relation Name'] : null);

            if ($alias !== null && isset($scans[$alias], $aliases[$alias]) && $scans[$alias]['cost'] >= $this->minTotalCost) {
                $columns = $this->filterColumns($scans[$alias]['filter']);

                foreach ($node['Sort Key'] as $key) {
                    if (is_string($key) && preg_match('/^(?:(\w+)\.)?"?(\w+)"?(?:\s+(?:DESC|ASC))?/i', $key, $match) === 1 && ($match[1] === '' || $match[1] === $alias)) {
                        $columns[] = $match[2];
                    }
                }

                $suggestions[] = $this->suggestion($aliases[$alias], array_values(array_unique($columns)), 'sort', $connection);
            }
        }

        foreach ((array) ($node['Plans'] ?? []) as $child) {
            if (is_array($child)) {
                $this->sortSuggestions($child, $aliases, $scans, $suggestions, $connection);
            }
        }
    }

    /**
     * Columns compared in a filter, equality first (they lead the index), then ranges.
     *
     * @return list<string>
     */
    public function filterColumns(string $filter): array
    {
        if ($filter === '') {
            return [];
        }

        $text = (string) preg_replace('/::[a-z_]+(?: (?:without|with) time zone)?(?:\[\])?/i', '', $filter);
        $text = str_replace(['(', ')'], ' ', $text);

        if (preg_match_all('/(?:\b\w+\.)?\b([a-z_][a-z0-9_]*)\s*(=|<>|!=|<=|>=|<|>|~~\*?|!~~\*?|IS NOT NULL|IS NULL|IS)(?!\s*\w+\.\w)/i', $text, $matches, PREG_SET_ORDER) < 1) {
            return [];
        }

        $equality = [];
        $range = [];

        foreach ($matches as [, $column, $operator]) {
            if (in_array(strtolower($column), self::KEYWORDS, true)) {
                continue;
            }

            if (in_array(strtoupper($operator), ['=', 'IS', 'IS NULL'], true)) {
                $equality[$column] = true;
            } else {
                $range[$column] = true;
            }
        }

        return array_values(array_unique([...array_keys($equality), ...array_keys($range)]));
    }

    /**
     * @param  list<string>  $columns
     * @return array{table: string, columns: list<string>, sql: string, reason: string}|null
     */
    private function suggestion(string $table, array $columns, string $reason, ?string $connection): ?array
    {
        if (str_starts_with($table, 'pg_') || preg_match(self::IDENTIFIER, $table) !== 1) {
            return null;
        }

        $columns = array_values(array_filter($columns, static fn (string $column): bool => preg_match(self::IDENTIFIER, $column) === 1));

        if ($columns === []) {
            return null;
        }

        if (! in_array('tenant_id', $columns, true) && $this->hasTenantColumn($table, $connection)) {
            array_unshift($columns, 'tenant_id');
        }

        $name = 'idx_'.$table.'_'.implode('_', $columns);

        if (strlen($name) > 63) {
            $name = substr($name, 0, 54).'_'.substr(sha1($name), 0, 8);
        }

        return [
            'table' => $table,
            'columns' => $columns,
            'sql' => sprintf('CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s);', $name, $table, implode(', ', $columns)),
            'reason' => $reason,
        ];
    }

    private function hasTenantColumn(string $table, ?string $connection): bool
    {
        try {
            return Schema::connection($connection)->hasColumn($table, 'tenant_id');
        } catch (Throwable) {
            return false;
        }
    }
}
