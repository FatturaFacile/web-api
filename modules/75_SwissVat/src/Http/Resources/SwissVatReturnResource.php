<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SwissVat\Models\SwissVatReturn;

/**
 * @mixin SwissVatReturn
 */
final class SwissVatReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'fiscal_year' => (int) $this->fiscal_year,
            'period_type' => $this->period_type,
            'period_number' => (int) $this->period_number,
            'period_label' => $this->period_label,
            'period_from' => $this->period_from->toDateString(),
            'period_to' => $this->period_to->toDateString(),
            'accounting_method' => $this->accounting_method,
            'consideration_basis' => $this->consideration_basis,
            'currency' => $this->currency,
            'status' => $this->status,
            'box_200_total_turnover' => (string) $this->box_200_total_turnover,
            'box_289_total_deductions' => (string) $this->box_289_total_deductions,
            'box_299_taxable_turnover' => (string) $this->box_299_taxable_turnover,
            'box_399_total_output_tax' => (string) $this->box_399_total_output_tax,
            'box_479_total_input_tax' => (string) $this->box_479_total_input_tax,
            'box_500_payable_amount' => (string) $this->box_500_payable_amount,
            'box_510_credit_amount' => (string) $this->box_510_credit_amount,
            'gross_turnover' => (string) $this->gross_turnover,
            'invoiced_vat_total' => (string) $this->invoiced_vat_total,
            'forfeited_input_vat' => (string) $this->forfeited_input_vat,
            'sss_revenue_adjustment' => (string) $this->sss_revenue_adjustment,
            'bezugsteuer_base' => (string) $this->bezugsteuer_base,
            'bezugsteuer_tax' => (string) $this->bezugsteuer_tax,
            'adjustments_box_410' => (string) $this->adjustments_box_410,
            'adjustments_box_415' => (string) $this->adjustments_box_415,
            'adjustments_box_420' => (string) $this->adjustments_box_420,
            'afc_boxes' => $this->afc_boxes ?? [],
            'quadratura_passed' => (bool) $this->quadratura_passed,
            'quadratura_report' => $this->quadratura_report ?? [],
            'reconciliation_lines' => $this->reconciliation_lines ?? [],
            'finalized_at' => $this->finalized_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'suissetax_reference' => $this->suissetax_reference,
        ];
    }
}
