<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Rebates\Models\RebateAccrualRun;

/**
 * An accrual run of a supplier rebate agreement with the invoices that contributed to it.
 *
 * @mixin RebateAccrualRun
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
            'agreement_id' => $this->agreement_id,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'status' => $this->status,
            'base_amount' => $this->base_amount,
            'cumulative_rebate' => $this->cumulative_rebate,
            'delta_amount' => $this->delta_amount,
            'journal_entry_id' => $this->journal_entry_id,
            'reversal_journal_entry_id' => $this->reversal_journal_entry_id,
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversal_reason' => $this->reversal_reason,
            'lines' => $this->whenLoaded('lines', fn (): array => $this->lines->map(static fn ($line): array => [
                'passive_invoice_id' => $line->passive_invoice_id,
                'invoice_number' => $line->invoice_number,
                'document_type' => $line->document_type,
                'invoice_date' => $line->invoice_date?->toDateString(),
                'net_amount' => $line->net_amount,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
