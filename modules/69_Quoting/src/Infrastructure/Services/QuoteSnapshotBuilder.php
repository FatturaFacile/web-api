<?php

declare(strict_types=1);

namespace Modules\Quoting\Infrastructure\Services;

use Modules\Quoting\Domain\Services\QuotePricingCalculator;
use Modules\Quoting\Infrastructure\Models\CpqQuote;

/**
 * Plain-array view of a revision (what the calculator, the diff and the frozen snapshot all consume) and the
 * customer-safe projection of it (no unit cost, no margin).
 */
final class QuoteSnapshotBuilder
{
    public function __construct(private readonly QuotePricingCalculator $calculator) {}

    public function roundingStep(CpqQuote $quote): string
    {
        return $quote->jurisdiction === 'CH' && $quote->currency === 'CHF' ? '0.05' : '0.00';
    }

    /**
     * @return array{header: array<string, mixed>, sections: list<array<string, mixed>>, lines: list<array<string, mixed>>}
     */
    public function structure(CpqQuote $quote): array
    {
        $quote->loadMissing(['sections', 'lines']);
        $sectionOrigins = $quote->sections->mapWithKeys(static fn ($s): array => [$s->id => $s->origin_id ?? $s->id])->all();

        return [
            'header' => [
                'quote_number' => $quote->quote_number,
                'revision' => $quote->revision,
                'currency' => $quote->currency,
                'jurisdiction' => $quote->jurisdiction,
                'locale' => $quote->locale,
                'exchange_rate' => (string) $quote->exchange_rate,
                'customer_name' => $quote->customer_name,
                'billing_address' => $quote->billing_address,
                'shipping_address' => $quote->shipping_address,
                'valid_until' => $quote->valid_until?->toDateString(),
                'payment_terms' => $quote->payment_terms,
                'incoterms' => $quote->incoterms,
                'cover_letter' => $quote->cover_letter,
                'terms_and_conditions' => $quote->terms_and_conditions,
                'header_discount_percent' => (string) $quote->header_discount_percent,
                'signature_level' => $quote->signature_level->value,
            ],
            'sections' => $quote->sections->map(static fn ($s): array => [
                'id' => $s->id, 'origin_id' => $s->origin_id ?? $s->id, 'title' => $s->title, 'description' => $s->description,
                'page_break_before' => (bool) $s->page_break_before, 'discount_percent' => (string) $s->discount_percent,
            ])->values()->all(),
            'lines' => $quote->lines->map(fn ($l): array => [
                'id' => $l->id, 'origin_id' => $l->origin_id ?? $l->id, 'section_id' => $l->section_id,
                'section_origin_id' => $l->section_id === null ? null : ($sectionOrigins[$l->section_id] ?? $l->section_id),
                'line_type' => $l->line_type->value, 'selection_mode' => $l->selection_mode->value, 'alternative_group' => $l->alternative_group,
                'is_default_selected' => $l->is_default_selected, 'sku' => $l->sku, 'description' => $l->description, 'long_description' => $l->long_description,
                'uom' => $l->uom, 'resource_role' => $l->resource_role, 'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price,
                'unit_cost' => (string) $l->unit_cost, 'discount_percent' => (string) $l->discount_percent, 'discount_fixed' => (string) $l->discount_fixed,
                'tier_prices' => $l->tier_prices, 'tax_rate' => (string) $l->tax_rate, 'product_id' => $l->product_id,
            ])->values()->all(),
        ];
    }

    /**
     * Internal snapshot (with costs) including the totals of the default selection: it is what gets frozen and hashed.
     *
     * @return array<string, mixed>
     */
    public function snapshot(CpqQuote $quote): array
    {
        $structure = $this->structure($quote);
        $priced = $this->calculator->calculate($structure['sections'], $structure['lines'], [], (string) $quote->header_discount_percent, $this->roundingStep($quote));

        return $structure + ['totals' => $priced['totals'], 'tax_breakdown' => $priced['tax_breakdown']];
    }

    /**
     * What the customer sees for a frozen revision under their current selections: unit costs, margins and the internal
     * totals are stripped.
     *
     * @param  array<string, bool>  $selections
     * @return array<string, mixed>
     */
    public function publicView(CpqQuote $quote, array $selections): array
    {
        /** @var array<string, mixed> $snapshot */
        $snapshot = $quote->frozen_snapshot ?? $this->snapshot($quote);
        $header = (array) $snapshot['header'];
        $sections = (array) $snapshot['sections'];
        $lines = (array) $snapshot['lines'];
        $priced = $this->calculator->calculate($sections, $lines, $selections, (string) $header['header_discount_percent'], $this->roundingStep($quote));
        $included = $this->calculator->resolveIncluded($lines, $selections);

        $totals = $priced['totals'];
        unset($totals['cost'], $totals['margin'], $totals['margin_percent']);

        return [
            'quote_number' => $quote->quote_number,
            'status' => $quote->status->value,
            'currency' => $header['currency'],
            'locale' => $header['locale'],
            'customer_name' => $header['customer_name'],
            'valid_until' => $header['valid_until'],
            'payment_terms' => $header['payment_terms'],
            'incoterms' => $header['incoterms'],
            'cover_letter' => $header['cover_letter'],
            'terms_and_conditions' => $header['terms_and_conditions'],
            'signature_level' => $header['signature_level'],
            'sections' => array_map(static fn (array $s): array => [
                'id' => $s['id'], 'title' => $s['title'], 'description' => $s['description'], 'page_break_before' => $s['page_break_before'],
                'subtotal' => $priced['sections'][(string) $s['id']]['subtotal'] ?? '0.00',
            ], $sections),
            'lines' => array_map(static fn (array $l): array => [
                'id' => $l['id'], 'section_id' => $l['section_id'], 'line_type' => $l['line_type'], 'selection_mode' => $l['selection_mode'],
                'alternative_group' => $l['alternative_group'], 'sku' => $l['sku'], 'description' => $l['description'], 'long_description' => $l['long_description'],
                'uom' => $l['uom'], 'quantity' => $l['quantity'], 'unit_price' => $priced['lines'][(string) $l['id']]['unit_price'] ?? $l['unit_price'],
                'tax_rate' => $l['tax_rate'], 'selected' => $included[(string) $l['id']] ?? false, 'net' => $priced['lines'][(string) $l['id']]['net'] ?? '0.00',
            ], $lines),
            'tax_breakdown' => $priced['tax_breakdown'],
            'totals' => $totals,
        ];
    }
}
