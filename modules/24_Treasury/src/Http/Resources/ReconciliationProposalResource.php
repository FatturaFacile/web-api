<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Models\ReconciliationProposal;

/**
 * @mixin ReconciliationProposal
 */
final class ReconciliationProposalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bank_statement_line_id' => $this->bank_statement_line_id,
            'document_type' => $this->document_type,
            'document_id' => $this->document_id,
            'document_number' => $this->evidence['document_number'] ?? null,
            'rule' => $this->rule,
            'confidence' => (int) $this->confidence,
            'kind' => $this->evidence['kind'] ?? null,
            'reasons' => $this->evidence['reasons'] ?? [],
            'matched_amount' => (string) $this->matched_amount,
            'fee_amount' => (string) $this->fee_amount,
            'fx_difference' => (string) $this->fx_difference,
            'residual_amount' => (string) $this->residual_amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'applied_at' => $this->applied_at?->toIso8601String(),
        ];
    }
}
