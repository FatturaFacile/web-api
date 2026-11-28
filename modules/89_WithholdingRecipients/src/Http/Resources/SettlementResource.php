<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\WithholdingRecipients\Models\RecipientSettlement;

/**
 * @mixin RecipientSettlement
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
            'recipient_profile_id' => $this->recipient_profile_id,
            'recipient_name' => $this->whenLoaded('profile', fn (): ?string => $this->profile?->display_name),
            'regime' => $this->regime,
            'jurisdiction' => $this->jurisdiction,
            'currency' => $this->currency,
            'document_number' => $this->document_number,
            'service_date' => $this->service_date->toDateString(),
            'payment_date' => $this->payment_date->toDateString(),
            'tax_year' => $this->tax_year,
            'accrual_month' => $this->accrual_month,
            'status' => $this->status,
            'gross_amount' => $this->gross_amount,
            'flat_rate_deduction' => $this->flat_rate_deduction,
            'taxable_base' => $this->taxable_base,
            'withholding_rate' => $this->withholding_rate,
            'irpef_withholding' => $this->irpef_withholding,
            'inps_base' => $this->inps_base,
            'inps_total' => $this->inps_total,
            'inps_worker' => $this->inps_worker,
            'inps_company' => $this->inps_company,
            'stamp_duty' => $this->stamp_duty,
            'enasarco_base' => $this->enasarco_base,
            'enasarco_agent' => $this->enasarco_agent,
            'enasarco_company' => $this->enasarco_company,
            'firr_accrual' => $this->firr_accrual,
            'avs_base' => $this->avs_base,
            'avs_employee' => $this->avs_employee,
            'avs_employer' => $this->avs_employer,
            'acquisition_expenses' => $this->acquisition_expenses,
            'source_tax' => $this->source_tax,
            'non_taxable_amount' => $this->non_taxable_amount,
            'non_taxable_code' => $this->non_taxable_code,
            'cu_causal' => $this->cu_causal,
            'net_payable' => $this->net_payable,
            'company_cost' => $this->company_cost,
            'f24_due_date' => $this->f24_due_date?->toDateString(),
            'f24_lines' => $this->f24_lines ?? [],
            'uniemens_required' => (bool) $this->uniemens_required,
            'inl_notification_id' => $this->inl_notification_id,
            'payment_reference' => $this->payment_reference,
            'breakdown' => $this->breakdown,
            'warnings' => $this->warnings ?? [],
            'notes' => $this->notes,
        ];
    }
}
