<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Quoting\Domain\Enums\QuoteStatus;
use Modules\Quoting\Domain\ValueObjects\QuoteNumber;
use Modules\Quoting\Infrastructure\Models\CpqQuote;
use Modules\Quoting\Infrastructure\Models\CpqQuoteLine;
use Modules\Quoting\Infrastructure\Models\CpqQuoteSection;
use Modules\Quoting\Infrastructure\Services\QuoteSnapshotBuilder;

/**
 * Writes the header, the sections and the lines of a DRAFT revision and keeps its running totals and margin current.
 * Sections come with a client `key` that the lines reference as `section_key`.
 */
final class QuoteDraftWriter
{
    private const array HeaderFields = [
        'jurisdiction', 'locale', 'contact_id', 'sales_rep_id', 'customer_name', 'customer_email', 'customer_phone', 'billing_address', 'shipping_address',
        'currency', 'exchange_rate', 'valid_until', 'payment_terms', 'incoterms', 'cover_letter', 'terms_and_conditions', 'header_discount_percent',
        'min_margin_percent', 'auto_convert', 'auto_convert_target', 'signature_level',
    ];

    private const array LineFields = [
        'line_type', 'selection_mode', 'alternative_group', 'is_default_selected', 'product_id', 'sku', 'description', 'long_description', 'uom',
        'resource_role', 'quantity', 'unit_price', 'unit_cost', 'discount_percent', 'discount_fixed', 'tier_prices', 'tax_rate',
    ];

    public function __construct(private readonly QuoteSnapshotBuilder $snapshots) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, ?string $userId, array $data): CpqQuote
    {
        return DB::transaction(function () use ($tenantId, $userId, $data): CpqQuote {
            $prefix = (string) config('quoting.number_prefix', 'OFF');
            $year = (int) now()->format('Y');
            $sequence = (int) CpqQuote::query()->where('number_prefix', $prefix)->where('number_year', $year)->max('number_sequence') + 1;
            $number = new QuoteNumber($prefix, $year, $sequence);

            $quote = new CpqQuote([
                'tenant_id' => $tenantId,
                'quote_number' => (string) $number,
                'number_prefix' => $prefix,
                'number_year' => $year,
                'number_sequence' => $sequence,
                'revision' => 1,
                'status' => QuoteStatus::Draft->value,
                'created_by' => $userId,
            ]);
            $quote->forceFill(['root_quote_id' => $quote->id]);
            $this->fillHeader($quote, $data);
            $quote->save();
            $quote->refresh();
            $this->replaceStructure($quote, (array) ($data['sections'] ?? []), (array) ($data['lines'] ?? []));

            return $this->recalculate($quote);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CpqQuote $quote, ?string $userId, array $data): CpqQuote
    {
        $quote->assertEditable();

        return DB::transaction(function () use ($quote, $userId, $data): CpqQuote {
            $this->fillHeader($quote, $data);
            $quote->updated_by = $userId;
            $quote->save();

            if (array_key_exists('sections', $data) || array_key_exists('lines', $data)) {
                $this->replaceStructure($quote, (array) ($data['sections'] ?? []), (array) ($data['lines'] ?? []));
            }

            return $this->recalculate($quote);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  list<array<string, mixed>>  $lines
     */
    public function replaceStructure(CpqQuote $quote, array $sections, array $lines): void
    {
        $quote->assertEditable();
        // Lines and sections sent back with their id keep the lineage (origin) the revision diff matches on.
        $lineOrigins = CpqQuoteLine::query()->where('quote_id', $quote->id)->get(['id', 'origin_id'])->mapWithKeys(static fn (CpqQuoteLine $l): array => [$l->id => $l->origin_id ?? $l->id])->all();
        $sectionOrigins = CpqQuoteSection::query()->where('quote_id', $quote->id)->get(['id', 'origin_id'])->mapWithKeys(static fn (CpqQuoteSection $s): array => [$s->id => $s->origin_id ?? $s->id])->all();
        CpqQuoteLine::query()->where('quote_id', $quote->id)->forceDelete();
        CpqQuoteSection::query()->where('quote_id', $quote->id)->forceDelete();

        $sectionIds = [];
        foreach ($sections as $index => $section) {
            $row = CpqQuoteSection::query()->create([
                'tenant_id' => $quote->tenant_id,
                'quote_id' => $quote->id,
                'origin_id' => $section['origin_id'] ?? (isset($section['id']) ? ($sectionOrigins[$section['id']] ?? null) : null),
                'title' => $section['title'],
                'description' => $section['description'] ?? null,
                'page_break_before' => (bool) ($section['page_break_before'] ?? false),
                'discount_percent' => $section['discount_percent'] ?? 0,
                'sort_order' => $index,
            ]);
            $sectionIds[(string) ($section['key'] ?? $index)] = $row->id;
        }

        foreach ($lines as $index => $line) {
            $attributes = array_intersect_key($line, array_flip(self::LineFields));
            CpqQuoteLine::query()->create($attributes + [
                'tenant_id' => $quote->tenant_id,
                'quote_id' => $quote->id,
                'origin_id' => $line['origin_id'] ?? (isset($line['id']) ? ($lineOrigins[$line['id']] ?? null) : null),
                'section_id' => isset($line['section_key']) ? ($sectionIds[(string) $line['section_key']] ?? null) : null,
                'sort_order' => $index,
            ]);
        }
    }

    public function recalculate(CpqQuote $quote): CpqQuote
    {
        $quote->unsetRelation('sections')->unsetRelation('lines');
        $snapshot = $this->snapshots->snapshot($quote);
        $totals = $snapshot['totals'];
        $threshold = $quote->min_margin_percent;

        $quote->forceFill([
            'total_net' => $totals['net'], 'total_tax' => $totals['tax'], 'total_gross' => $totals['payable'], 'total_cost' => $totals['cost'],
            'margin_percent' => $totals['margin_percent'],
            'requires_approval' => $threshold !== null && $totals['margin_percent'] !== null && bccomp((string) $totals['margin_percent'], (string) $threshold, 4) < 0,
        ])->save();

        return $quote;
    }

    /**
     * Applies a new ordering of the lines (and the section each belongs to) and of the sections.
     *
     * @param  list<string>  $sectionOrder
     * @param  list<array{id: string, section_id: string|null}>  $lineOrder
     */
    public function reorder(CpqQuote $quote, array $sectionOrder, array $lineOrder): CpqQuote
    {
        $quote->assertEditable();

        return DB::transaction(function () use ($quote, $sectionOrder, $lineOrder): CpqQuote {
            foreach ($sectionOrder as $index => $id) {
                CpqQuoteSection::query()->where('quote_id', $quote->id)->whereKey($id)->update(['sort_order' => $index]);
            }
            foreach ($lineOrder as $index => $line) {
                CpqQuoteLine::query()->where('quote_id', $quote->id)->whereKey($line['id'])->update(['sort_order' => $index, 'section_id' => $line['section_id']]);
            }

            return $this->recalculate($quote);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillHeader(CpqQuote $quote, array $data): void
    {
        $quote->fill(array_intersect_key($data, array_flip(self::HeaderFields)));
    }
}
