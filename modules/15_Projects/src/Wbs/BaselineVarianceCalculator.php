<?php

declare(strict_types=1);

namespace Modules\Projects\Wbs;

use Carbon\CarbonImmutable;
use Modules\Projects\Support\Money;

/**
 * Compares the WBS of a project with a captured baseline: schedule variance in calendar days (positive = later than
 * baselined) and effort, cost and revenue variance (positive = more than baselined), per node and for the whole project.
 *
 * @phpstan-type Snapshot array{id: string, wbs_code: string, title: string, node_type: string, start_date: string|null, end_date: string|null, planned_hours: string, planned_cost: string, planned_revenue: string, percent_complete: string}
 */
final class BaselineVarianceCalculator
{
    private const array Amounts = ['planned_hours', 'planned_cost', 'planned_revenue'];

    /**
     * @param  list<Snapshot>  $baseline
     * @param  list<Snapshot>  $current
     * @return array{nodes: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function compare(array $baseline, array $current, ?string $baselineFinish = null, ?string $currentFinish = null): array
    {
        $baselineById = [];

        foreach ($baseline as $row) {
            $baselineById[$row['id']] = $row;
        }

        $currentById = [];

        foreach ($current as $row) {
            $currentById[$row['id']] = $row;
        }

        $rows = [];

        foreach ($current as $row) {
            $rows[] = isset($baselineById[$row['id']])
                ? $this->changed($baselineById[$row['id']], $row)
                : $this->added($row);
        }

        foreach ($baseline as $row) {
            if (! isset($currentById[$row['id']])) {
                $rows[] = $this->removed($row);
            }
        }

        $summary = $this->summary($baseline, $current, $baselineFinish, $currentFinish);

        return ['nodes' => $rows, 'summary' => $summary];
    }

    /**
     * @param  Snapshot  $before
     * @param  Snapshot  $after
     * @return array<string, mixed>
     */
    private function changed(array $before, array $after): array
    {
        $row = [
            'node_id' => $after['id'],
            'wbs_code' => $after['wbs_code'],
            'title' => $after['title'],
            'node_type' => $after['node_type'],
            'change' => 'unchanged',
            'baseline_start_date' => $before['start_date'],
            'baseline_end_date' => $before['end_date'],
            'start_date' => $after['start_date'],
            'end_date' => $after['end_date'],
            'start_variance_days' => $this->days($before['start_date'], $after['start_date']),
            'finish_variance_days' => $this->days($before['end_date'], $after['end_date']),
        ];

        $differs = ($row['start_variance_days'] ?? 0) !== 0 || ($row['finish_variance_days'] ?? 0) !== 0;

        foreach (self::Amounts as $field) {
            $row["baseline_{$field}"] = $before[$field];
            $row[$field] = $after[$field];
            $row["{$field}_variance"] = Money::round(Money::sub($after[$field], $before[$field]));
            $differs = $differs || ! Money::isZero($row["{$field}_variance"]);
        }

        $row['change'] = $differs ? 'changed' : 'unchanged';

        return $row;
    }

    /**
     * @param  Snapshot  $row
     * @return array<string, mixed>
     */
    private function added(array $row): array
    {
        $result = [
            'node_id' => $row['id'], 'wbs_code' => $row['wbs_code'], 'title' => $row['title'], 'node_type' => $row['node_type'],
            'change' => 'added',
            'baseline_start_date' => null, 'baseline_end_date' => null,
            'start_date' => $row['start_date'], 'end_date' => $row['end_date'],
            'start_variance_days' => null, 'finish_variance_days' => null,
        ];

        foreach (self::Amounts as $field) {
            $result["baseline_{$field}"] = '0.00';
            $result[$field] = $row[$field];
            $result["{$field}_variance"] = Money::round($row[$field]);
        }

        return $result;
    }

    /**
     * @param  Snapshot  $row
     * @return array<string, mixed>
     */
    private function removed(array $row): array
    {
        $result = [
            'node_id' => $row['id'], 'wbs_code' => $row['wbs_code'], 'title' => $row['title'], 'node_type' => $row['node_type'],
            'change' => 'removed',
            'baseline_start_date' => $row['start_date'], 'baseline_end_date' => $row['end_date'],
            'start_date' => null, 'end_date' => null,
            'start_variance_days' => null, 'finish_variance_days' => null,
        ];

        foreach (self::Amounts as $field) {
            $result["baseline_{$field}"] = $row[$field];
            $result[$field] = '0.00';
            $result["{$field}_variance"] = Money::round(Money::neg($row[$field]));
        }

        return $result;
    }

    /**
     * Totals use the top-level nodes (no dot in the WBS code), which already hold the roll-up of everything below.
     *
     * @param  list<Snapshot>  $baseline
     * @param  list<Snapshot>  $current
     * @return array<string, mixed>
     */
    private function summary(array $baseline, array $current, ?string $baselineFinish, ?string $currentFinish): array
    {
        $summary = [
            'baseline_finish_date' => $baselineFinish ?? $this->latestEnd($baseline),
            'finish_date' => $currentFinish ?? $this->latestEnd($current),
        ];
        $summary['finish_variance_days'] = $this->days($summary['baseline_finish_date'], $summary['finish_date']);

        foreach (self::Amounts as $field) {
            $before = $this->topLevelTotal($baseline, $field);
            $after = $this->topLevelTotal($current, $field);
            $summary["baseline_{$field}"] = $before;
            $summary[$field] = $after;
            $summary["{$field}_variance"] = Money::round(Money::sub($after, $before));
        }

        return $summary;
    }

    /**
     * @param  list<Snapshot>  $rows
     */
    private function topLevelTotal(array $rows, string $field): string
    {
        $total = '0';

        foreach ($rows as $row) {
            if (! str_contains($row['wbs_code'], '.')) {
                $total = Money::add($total, $row[$field]);
            }
        }

        return Money::round($total);
    }

    /**
     * @param  list<Snapshot>  $rows
     */
    private function latestEnd(array $rows): ?string
    {
        $latest = null;

        foreach ($rows as $row) {
            if ($row['end_date'] !== null && ($latest === null || $row['end_date'] > $latest)) {
                $latest = $row['end_date'];
            }
        }

        return $latest;
    }

    private function days(?string $from, ?string $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        return (int) CarbonImmutable::parse($from)->startOfDay()->diffInDays(CarbonImmutable::parse($to)->startOfDay(), false);
    }
}
