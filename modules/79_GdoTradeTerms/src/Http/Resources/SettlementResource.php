<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GdoTradeTerms\Models\GdoSettlement;
use Modules\GdoTradeTerms\Models\GdoSettlementLine;

/**
 * A settlement run: accrued against earned, the credit note and the reconciliation of the promotional invoice of the retailer.
 *
 * @mixin GdoSettlement
 */
final class SettlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'contract' => $this->whenLoaded('contract', fn (): array => ['id' => $this->contract->id, 'code' => $this->contract->code, 'name' => $this->contract->name, 'currency' => $this->contract->currency, 'jurisdiction' => $this->contract->jurisdiction->value, 'tolerance_percent' => $this->contract->tolerance_percent]),
            'code' => $this->code,
            'kind' => $this->kind->value,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'status' => $this->status->value,
            'tax_treatment' => $this->tax_treatment->value,
            'currency' => $this->currency,
            'accrued_total' => $this->accrued_total,
            'earned_total' => $this->earned_total,
            'variance_total' => $this->variance_total,
            'credit_note_amount' => $this->credit_note_amount,
            'promo_amount' => $this->promo_amount,
            'tax_rate' => $this->tax_rate,
            'credit_note_id' => $this->credit_note_id,
            'claim_invoice_number' => $this->claim_invoice_number,
            'claim_invoice_date' => $this->claim_invoice_date?->toDateString(),
            'claim_net' => $this->claim_net,
            'claim_tax' => $this->claim_tax,
            'claim_variance' => $this->claim_variance,
            'dispute_reason' => $this->dispute_reason,
            'release_journal_entry_id' => $this->release_journal_entry_id,
            'set_off_journal_entry_id' => $this->set_off_journal_entry_id,
            'settled_on' => $this->settled_on?->toDateString(),
            'notes' => $this->notes,
            'lines' => $this->whenLoaded('lines', fn (): array => $this->lines->map(static fn (GdoSettlementLine $line): array => [
                'id' => $line->id,
                'source_type' => $line->source_type->value,
                'source_id' => $line->source_id,
                'source_label' => $line->source_label,
                'treatment' => $line->treatment->value,
                'base_value' => $line->base_value,
                'accrued_amount' => $line->accrued_amount,
                'earned_amount' => $line->earned_amount,
                'variance_amount' => $line->variance_amount,
                'cumulative_earned' => $line->cumulative_earned,
                'status' => $line->status->value,
            ])->values()->all()),
        ];
    }
}
