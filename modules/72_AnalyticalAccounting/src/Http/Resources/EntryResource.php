<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Resources;

use Modules\AnalyticalAccounting\Models\AnalyticalEntry;

/**
 * API representation of an analytical ledger entry.
 *
 * @property AnalyticalEntry $resource
 */
final class EntryResource extends AttributeResource
{
    protected function fields(): array
    {
        return ['id', 'entry_type', 'posting_date', 'period_key', 'currency', 'description', 'source_type', 'source_id', 'gl_journal_entry_id', 'invoice_id', 'apportionment_run_id', 'is_reversed', 'reversed_by_entry_id'];
    }

    protected function extra(): array
    {
        return ['lines' => $this->resource->relationLoaded('lines') ? $this->resource->lines->map(static fn ($l): array => $l->only(['id', 'line_number', 'cost_center_id', 'profit_center_id', 'project_id', 'product_line_id', 'cost_element_type', 'amount', 'split_percentage', 'derivation_source', 'derivation_meta', 'description'])) : []];
    }
}
