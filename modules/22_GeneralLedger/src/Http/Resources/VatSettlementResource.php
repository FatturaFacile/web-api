<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class VatSettlementResource extends JsonResource
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
            'settlement_period' => $this->settlement_period,
            'output_vat_amount' => (float) $this->output_vat_amount,
            'input_vat_amount' => (float) $this->input_vat_amount,
            'net_vat_payable' => (float) $this->net_vat_payable,
            'net_vat_credit' => (float) $this->net_vat_credit,
            'status' => $this->status,
            'settlement_journal_id' => $this->settlement_journal_id ? (string) $this->settlement_journal_id : null,
        ];
    }
}
