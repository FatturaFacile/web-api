<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ForeignVatRefund\Models\CountryVatRefundRule;

/**
 * @mixin CountryVatRefundRule
 */
final class RuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'is_global' => $this->tenant_id === null,
            'home_country_code' => $this->home_country_code,
            'refund_country_code' => $this->refund_country_code,
            'regime_type' => $this->regime_type->value,
            'has_reciprocity' => $this->has_reciprocity,
            'expense_category_code' => $this->expense_category_code,
            'sub_code' => $this->sub_code,
            'deductible_percentage' => (string) $this->deductible_percentage,
            'min_annual_amount' => $this->min_annual_amount === null ? null : (string) $this->min_annual_amount,
            'min_quarterly_amount' => $this->min_quarterly_amount === null ? null : (string) $this->min_quarterly_amount,
            'currency_code' => $this->currency_code,
            'filing_deadline_month' => $this->filing_deadline_month,
            'filing_deadline_day' => $this->filing_deadline_day,
            'vat_rates' => $this->vat_rates,
            'requires_fiscal_representative' => $this->requires_fiscal_representative,
            'requires_taxable_status_certificate' => $this->requires_taxable_status_certificate,
            'notes_key' => $this->notes_key,
            'is_active' => $this->is_active,
        ];
    }
}
