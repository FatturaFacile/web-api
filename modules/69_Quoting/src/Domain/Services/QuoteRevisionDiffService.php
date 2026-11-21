<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\Services;

/**
 * Structural diff of two revision snapshots ({header, sections, lines, totals}). Sections are matched by their
 * stable key (the section id is cloned into `origin_id` on a new revision), lines by their `origin_id` as well.
 */
final class QuoteRevisionDiffService
{
    private const array LineFields = [
        'description', 'quantity', 'unit_price', 'discount_percent', 'discount_fixed', 'tax_rate', 'selection_mode', 'alternative_group', 'is_default_selected', 'section_origin_id',
    ];

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array{sections: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, modified: list<array<string, mixed>>}, lines: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, modified: list<array<string, mixed>>}, totals: array<string, array{from: string, to: string, delta: string}>}
     */
    public function diff(array $from, array $to): array
    {
        return [
            'sections' => $this->diffSet((array) ($from['sections'] ?? []), (array) ($to['sections'] ?? []), ['title', 'discount_percent']),
            'lines' => $this->diffSet((array) ($from['lines'] ?? []), (array) ($to['lines'] ?? []), self::LineFields),
            'totals' => $this->diffTotals((array) ($from['totals'] ?? []), (array) ($to['totals'] ?? [])),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @param  list<string>  $fields
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, modified: list<array<string, mixed>>}
     */
    private function diffSet(array $before, array $after, array $fields): array
    {
        $beforeByKey = $this->byKey($before);
        $afterByKey = $this->byKey($after);
        $modified = [];

        foreach ($afterByKey as $key => $row) {
            if (! isset($beforeByKey[$key])) {
                continue;
            }

            $changes = [];
            foreach ($fields as $field) {
                $old = $beforeByKey[$key][$field] ?? null;
                $new = $row[$field] ?? null;
                if (! $this->same($old, $new)) {
                    $changes[$field] = ['from' => $old, 'to' => $new];
                }
            }

            if ($changes !== []) {
                $modified[] = ['key' => $key, 'label' => $row['description'] ?? $row['title'] ?? $key, 'changes' => $changes];
            }
        }

        return [
            'added' => array_values(array_diff_key($afterByKey, $beforeByKey)),
            'removed' => array_values(array_diff_key($beforeByKey, $afterByKey)),
            'modified' => $modified,
        ];
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array<string, array{from: string, to: string, delta: string}>
     */
    private function diffTotals(array $from, array $to): array
    {
        $out = [];
        foreach (['net', 'tax', 'gross', 'margin'] as $key) {
            $old = number_format((float) ($from[$key] ?? 0), 2, '.', '');
            $new = number_format((float) ($to[$key] ?? 0), 2, '.', '');
            $out[$key] = ['from' => $old, 'to' => $new, 'delta' => bcsub($new, $old, 2)];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function byKey(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(string) ($row['origin_id'] ?? $row['id'])] = $row;
        }

        return $map;
    }

    private function same(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return bccomp((string) $a, (string) $b, 4) === 0;
        }

        return $a === $b;
    }
}
