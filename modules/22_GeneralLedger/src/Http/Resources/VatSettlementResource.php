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
            'period_type' => $this->period_type,
            'year' => $this->year,
            'period' => $this->period,
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'country' => $this->country,
            'currency' => $this->currency,
            'regime' => $this->regime ?? 'effective',
            'turnover' => (string) $this->turnover,
            'taxable_turnover' => (string) $this->taxable_turnover,
            'gross_turnover' => (string) ($this->gross_turnover ?? '0.00'),
            'exempt_turnover' => (string) ($this->exempt_turnover ?? '0.00'),
            'acquisition_tax_base' => (string) ($this->acquisition_tax_base ?? '0.00'),
            'acquisition_tax_amount' => (string) ($this->acquisition_tax_amount ?? '0.0000'),
            'gross_input_vat_forfeited' => (string) ($this->gross_input_vat_forfeited ?? '0.0000'),
            'flat_rate_adjustment_amount' => (string) ($this->flat_rate_adjustment_amount ?? '0.0000'),
            'passive_operations' => (string) $this->passive_operations,
            'previous_credit' => bcadd((string) $this->prior_credit_used, '0', 2),
            'previous_debit' => (string) $this->previous_debit,
            'interest_amount' => (string) $this->interest_amount,
            'payable_amount' => (string) $this->payable_amount,
            'below_minimum_payment' => (bool) $this->below_minimum_payment,
            'rate_breakdown' => $this->rate_breakdown ?? [],
            'estv_boxes' => $this->estv_boxes ?? [],
            'warnings' => $this->warnings ?? [],
            'details' => $this->details,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_date' => $this->payment_date?->toDateString(),
            'payment_journal_id' => $this->payment_journal_id ? (string) $this->payment_journal_id : null,
        ];
    }
}
