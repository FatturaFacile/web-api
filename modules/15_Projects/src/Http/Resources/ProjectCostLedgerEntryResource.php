<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectCostLedgerEntry;

/**
 * API representation of a line of the project cost ledger (Mod 082).
 *
 * @mixin ProjectCostLedgerEntry
 */
final class ProjectCostLedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectCostLedgerEntry $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'project_id' => $entry->project_id,
            'wbs_node_id' => $entry->wbs_node_id,
            'cost_center_id' => $entry->cost_center_id,
            'source_type' => $entry->source_type,
            'source_document_id' => $entry->source_document_id,
            'source_document_reference' => $entry->source_document_reference,
            'committed_amount' => (string) $entry->committed_amount,
            'actual_amount' => (string) $entry->actual_amount,
            'currency_code' => $entry->currency_code,
            'exchange_rate' => (string) $entry->exchange_rate,
            'base_currency_committed_amount' => (string) $entry->base_currency_committed_amount,
            'base_currency_actual_amount' => (string) $entry->base_currency_actual_amount,
            'posting_date' => $entry->posting_date->toDateString(),
            'is_reconciled' => $entry->is_reconciled,
            'reconciliation_ref' => $entry->reconciliation_ref,
        ];
    }
}
