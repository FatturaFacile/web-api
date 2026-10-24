<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BankStatementLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankStatementLine
 */
class BankStatementLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bank_statement_id' => $this->bank_statement_id,
            'booking_date' => $this->booking_date->toDateString(),
            'value_date' => $this->value_date->toDateString(),
            'direction' => $this->direction,
            'amount' => (float) $this->amount,
            'fee_amount' => (float) $this->fee_amount,
            'gross_amount' => round((float) $this->amount + (float) $this->fee_amount, 2),
            'abi_code' => $this->abi_code,
            'reference' => $this->reference,
            'qr_reference' => $this->qr_reference,
            'description' => $this->description,
            'counterpart_name' => $this->counterpart_name,
            'counterpart_iban' => $this->counterpart_iban,
            'reconciled' => $this->reconciled,
            'matched_document_id' => $this->matched_document_id,
            'reconciliation_type' => $this->reconciliation_type,
            'reconciliation_category' => $this->reconciliation_category,
            'ledger_account_code' => $this->ledger_account_code,
            'reconciliation_notes' => $this->reconciliation_notes,
        ];
    }
}
