<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GdoTradeTerms\Models\GdoAccrualLine;
use Modules\GdoTradeTerms\Models\GdoAccrualRun;

/**
 * A monthly accrual run with its lines (the ledger of the accrued rebates and contributions) when they are loaded.
 *
 * @mixin GdoAccrualRun
 */
final class AccrualRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_key' => $this->period_key,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'status' => $this->status->value,
            'total_accrued' => $this->total_accrued,
            'contracts_count' => $this->contracts_count,
            'journal_entry_id' => $this->journal_entry_id,
            'reversal_journal_entry_id' => $this->reversal_journal_entry_id,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversal_reason' => $this->reversal_reason,
            'lines' => $this->whenLoaded('lines', fn (): array => $this->lines->map(static fn (GdoAccrualLine $line): array => [
                'id' => $line->id,
                'contract_id' => $line->contract_id,
                'contract_code' => $line->relationLoaded('contract') ? $line->contract?->code : null,
                'source_type' => $line->source_type->value,
                'source_id' => $line->source_id,
                'source_label' => $line->source_label,
                'currency' => $line->currency,
                'base_value' => $line->base_value,
                'earned_cumulative' => $line->earned_cumulative,
                'previously_accrued' => $line->previously_accrued,
                'delta_amount' => $line->delta_amount,
                'applied_rate_percent' => $line->applied_rate_percent,
                'status' => $line->status->value,
            ])->values()->all()),
        ];
    }
}
