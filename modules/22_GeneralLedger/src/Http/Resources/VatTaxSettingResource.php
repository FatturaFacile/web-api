<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class VatTaxSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'periodicity' => $this->periodicity,
            'regime' => $this->regime ?? 'effective',
            'sss_rate_1' => $this->sss_rate_1 !== null ? (string) $this->sss_rate_1 : null,
            'sss_rate_2' => $this->sss_rate_2 !== null ? (string) $this->sss_rate_2 : null,
            'flat_tax_rates' => $this->flat_tax_rates ?? [],
            'flat_rate_adjustment_account_id' => $this->flat_rate_adjustment_account_id,
            'acquisition_tax_account_id' => $this->acquisition_tax_account_id,
            'output_vat_account_ids' => $this->output_vat_account_ids ?? [],
            'input_vat_account_ids' => $this->input_vat_account_ids ?? [],
            'settlement_account_id' => $this->settlement_account_id,
            'interest_expense_account_id' => $this->interest_expense_account_id,
            'declarant_tax_code' => $this->declarant_tax_code,
            'declarant_role_code' => $this->declarant_role_code,
        ];
    }
}
