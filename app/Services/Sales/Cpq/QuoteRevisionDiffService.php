<?php

declare(strict_types=1);

namespace App\Services\Sales\Cpq;

use App\Models\Quote;
use App\Models\QuoteItem;
use Illuminate\Validation\ValidationException;

/**
 * Side-by-side comparison of two revisions of the same quote: lines added, removed and modified (quantity, price,
 * discount, selected variant), and the effect on the totals and on the margin.
 *
 * Lines are matched by product and description; a line that appears more than once is matched in order.
 */
final readonly class QuoteRevisionDiffService
{
    /**
     * Line fields compared, as numbers (decimal strings) or as plain values.
     *
     * @var list<string>
     */
    private const array LineFields = ['quantity', 'unit_price', 'discount_percentage', 'tax_rate', 'line_total', 'cost_price', 'labor_cost', 'is_optional', 'is_selected'];

    public function __construct(private MarginCalculator $calculator, private QuoteMarginSimulator $simulator) {}

    /**
     * @return array{from: array<string, mixed>, to: array<string, mixed>, items: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, modified: list<array<string, mixed>>}, totals: array<string, array{from: string|null, to: string|null, delta: string|null}>}
     *
     * @throws ValidationException when the quotes are not revisions of the same quote
     */
    public function diff(Quote $from, Quote $to): array
    {
        if ((string) $from->tenant_id !== (string) $to->tenant_id || $this->group($from) !== $this->group($to)) {
            throw ValidationException::withMessages(['quote' => __('sales.cpq.revisions_not_comparable')]);
        }

        $from->loadMissing('items');
        $to->loadMissing('items');
        $before = $this->keyed($from);
        $after = $this->keyed($to);

        $added = [];
        $modified = [];

        foreach ($after as $key => $item) {
            if (! isset($before[$key])) {
                $added[] = $this->summary($item);

                continue;
            }

            $changes = $this->changes($before[$key], $item);

            if ($changes !== []) {
                $modified[] = ['description' => (string) $item->description, 'product_id' => $item->product_id, 'changes' => $changes];
            }
        }

        $removed = [];

        foreach ($before as $key => $item) {
            if (! isset($after[$key])) {
                $removed[] = $this->summary($item);
            }
        }

        return [
            'from' => $this->header($from),
            'to' => $this->header($to),
            'items' => ['added' => $added, 'removed' => $removed, 'modified' => $modified],
            'totals' => $this->totals($from, $to),
        ];
    }

    private function group(Quote $quote): string
    {
        return (string) ($quote->quote_group_uuid ?? $quote->id);
    }

    /**
     * @return array<string, QuoteItem>
     */
    private function keyed(Quote $quote): array
    {
        $seen = [];
        $keyed = [];

        foreach ($quote->items as $item) {
            $base = ($item->product_id ?? '-').'|'.mb_strtolower(trim((string) $item->description));
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $keyed[$base.'#'.$seen[$base]] = $item;
        }

        return $keyed;
    }

    /**
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changes(QuoteItem $from, QuoteItem $to): array
    {
        $changes = [];

        foreach (self::LineFields as $field) {
            $old = $from->getAttribute($field);
            $new = $to->getAttribute($field);
            $differs = is_bool($old) || is_bool($new)
                ? (bool) $old !== (bool) $new
                : bccomp((string) ($old ?? '0'), (string) ($new ?? '0'), 4) !== 0;

            if ($differs) {
                $changes[$field] = ['from' => $old, 'to' => $new];
            }
        }

        $oldConfig = $this->configurationSelection($from);
        $newConfig = $this->configurationSelection($to);

        if ($oldConfig !== $newConfig) {
            $changes['configuration'] = ['from' => $oldConfig, 'to' => $newConfig];
        }

        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    private function configurationSelection(QuoteItem $item): array
    {
        $snapshot = $item->configuration_snapshot;

        if (! is_array($snapshot)) {
            return [];
        }

        $selection = $snapshot['selection'] ?? $snapshot;
        $selection = is_array($selection) ? $selection : [];
        ksort($selection);

        return $selection;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(QuoteItem $item): array
    {
        return [
            'description' => (string) $item->description,
            'product_id' => $item->product_id,
            'quantity' => (string) $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'discount_percentage' => (string) ($item->discount_percentage ?? '0'),
            'line_total' => (string) $item->line_total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function header(Quote $quote): array
    {
        return [
            'id' => $quote->id,
            'quote_number' => $quote->quote_number,
            'revision_number' => (int) ($quote->getAttribute('revision_number') ?? 0),
            'status' => $quote->status,
            'revision_notes' => $quote->revision_notes,
        ];
    }

    /**
     * @return array<string, array{from: string|null, to: string|null, delta: string|null}>
     */
    private function totals(Quote $from, Quote $to): array
    {
        $before = $this->calculator->calculate($this->simulator->lines($from))['totals'];
        $after = $this->calculator->calculate($this->simulator->lines($to))['totals'];

        $totals = [];

        foreach (['total_net' => [(string) $from->total_net, (string) $to->total_net], 'total_tax' => [(string) $from->total_tax, (string) $to->total_tax], 'total_gross' => [(string) $from->total_gross, (string) $to->total_gross], 'cost_total' => [$before['cost_total'], $after['cost_total']], 'margin_amount' => [$before['margin_amount'], $after['margin_amount']], 'margin_percent' => [$before['margin_percent'], $after['margin_percent']]] as $name => [$old, $new]) {
            $totals[$name] = ['from' => $old, 'to' => $new, 'delta' => $old === null || $new === null ? null : bcsub($new, $old, 2)];
        }

        return $totals;
    }
}
