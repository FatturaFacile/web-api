<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ItalianVat\Models\AnnualVatReconciliation;

/**
 * @mixin AnnualVatReconciliation
 */
final class AnnualVatReconciliationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'year' => $this->year,
            'status' => $this->status,
            'periods' => $this->periods,
            'total_output_vat' => (string) $this->total_output_vat,
            'total_input_vat' => (string) $this->total_input_vat,
            'pro_rata_percentage' => $this->pro_rata_percentage,
            'pro_rata_adjustment' => (string) $this->pro_rata_adjustment,
            'prior_year_credit' => (string) $this->prior_year_credit,
            'interest_total' => (string) $this->interest_total,
            'paid_total' => (string) $this->paid_total,
            'annual_debit' => (string) $this->annual_debit,
            'annual_credit' => (string) $this->annual_credit,
            'ledger_balance' => (string) $this->ledger_balance,
            'difference' => (string) $this->difference,
            'is_reconciled' => $this->is_reconciled,
            'diagnostics' => $this->diagnostics,
            'finalized_at' => $this->finalized_at?->toIso8601String(),
        ];
    }
}
