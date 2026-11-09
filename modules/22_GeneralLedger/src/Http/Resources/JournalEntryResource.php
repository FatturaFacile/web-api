<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class JournalEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'tenant_id' => (string) $this->tenant_id,
            'fiscal_period_id' => (string) $this->fiscal_period_id,
            'batch_id' => $this->batch_id ? (string) $this->batch_id : null,
            'entry_number' => $this->entry_number,
            'journal_number' => $this->journal_number,
            'fiscal_year_id' => $this->fiscal_year_id,
            'posting_date' => $this->posting_date?->toDateString(),
            'document_date' => $this->document_date?->toDateString(),
            'ledger_type' => $this->ledger_type,
            'journal_type' => $this->journal_type,
            'description' => $this->description,
            'source_document_type' => $this->source_document_type,
            'source_document_id' => $this->source_document_id ? (string) $this->source_document_id : null,
            'status' => $this->status,
            'total_amount' => (string) ($this->total_amount ?? '0.0000'),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversal_journal_entry_id' => $this->reversal_journal_entry_id ? (string) $this->reversal_journal_entry_id : null,
            'auto_reverse_on' => LedgerDateFormat::day($this->resource->getAttribute('auto_reverse_on')),
            'created_by' => $this->resource->getAttribute('created_by'),
            'submitted_by' => $this->resource->getAttribute('submitted_by'),
            'submitted_at' => LedgerDateFormat::instant($this->resource->getAttribute('submitted_at')),
            'approved_by' => $this->resource->getAttribute('approved_by'),
            'approved_at' => LedgerDateFormat::instant($this->resource->getAttribute('approved_at')),
            'rejected_by' => $this->resource->getAttribute('rejected_by'),
            'rejected_at' => LedgerDateFormat::instant($this->resource->getAttribute('rejected_at')),
            'rejection_reason' => $this->resource->getAttribute('rejection_reason'),
            'recurring_journal_id' => $this->resource->getAttribute('recurring_journal_id'),
            'lines' => JournalLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
