<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Rebates\Models\RebateSettlement;

/**
 * The settlement of the accrued rebate of an agreement.
 *
 * @mixin RebateSettlement
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
            'agreement_id' => $this->agreement_id,
            'agreement' => $this->whenLoaded('agreement', fn (): array => ['id' => $this->agreement->id, 'code' => $this->agreement->code, 'name' => $this->agreement->name, 'direction' => $this->agreement->direction]),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'settlement_type' => $this->settlement_type,
            'credit_note_id' => $this->credit_note_id,
            'settled_on' => $this->settled_on->toDateString(),
            'notes' => $this->notes,
            'status' => $this->status ?? RebateSettlement::Settled,
            'execution_mode' => $this->execution_mode,
            'gross_amount' => $this->gross_amount,
            'tax_rate' => $this->tax_rate,
            'tax_amount' => $this->tax_amount,
            'document_id' => $this->credit_note_id,
            'passive_invoice_id' => $this->passive_invoice_id,
            'set_off_amount' => $this->set_off_amount,
            'journal_entry_id' => $this->journal_entry_id,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'version' => $this->version ?? 1,
            'lines' => SettlementLineResource::collection($this->whenLoaded('lines')),
            'accruals' => AccrualResource::collection($this->whenLoaded('accruals')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
