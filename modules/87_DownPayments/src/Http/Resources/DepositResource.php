<?php

declare(strict_types=1);

namespace Modules\DownPayments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DownPayments\Models\EarnestAndSecurityDeposit;

/**
 * An earnest money or security deposit with its receipt data (exemption code, virtual stamp duty) and the way it was resolved.
 *
 * @mixin EarnestAndSecurityDeposit
 */
final class DepositResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'chain_id' => (string) $this->chain_id,
            'installment_id' => $this->installment_id,
            'deposit_type' => $this->deposit_type,
            'receipt_number' => $this->receipt_number,
            'issue_date' => $this->issue_date->toDateString(),
            'payment_date' => $this->payment_date?->toDateString(),
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'vat_exemption_code' => $this->vat_exemption_code,
            'marca_da_bollo_applied' => (bool) $this->marca_da_bollo_applied,
            'marca_da_bollo_amount' => (string) $this->marca_da_bollo_amount,
            'payment_account' => $this->payment_account,
            'status' => $this->status,
            'converted_invoice_id' => $this->converted_invoice_id,
            'journal_entry_id' => $this->journal_entry_id,
            'resolution_journal_entry_id' => $this->resolution_journal_entry_id,
            'resolution' => $this->resolution,
            'resolved_at' => $this->resolved_at?->toDateString(),
            'penalty_amount' => (string) $this->penalty_amount,
            'notes' => $this->notes,
        ];
    }
}
