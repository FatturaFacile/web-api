<?php

declare(strict_types=1);

namespace Modules\Intrastat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Intrastat\Models\IntrastatDeclarationLine;

/**
 * @mixin IntrastatDeclarationLine
 */
final class DeclarationLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'section' => $this->section,
            'line_number' => $this->line_number,
            'partner_country_code' => $this->partner_country_code,
            'partner_vat_number' => $this->partner_vat_number,
            'amount_eur' => $this->amount_eur,
            'amount_currency' => $this->amount_currency,
            'transaction_nature_a' => $this->transaction_nature_a,
            'transaction_nature_b' => $this->transaction_nature_b,
            'cn8_code' => $this->cn8_code,
            'net_mass_kg' => $this->net_mass_kg,
            'supplementary_units' => $this->supplementary_units,
            'statistical_value_eur' => $this->statistical_value_eur,
            'delivery_terms' => $this->delivery_terms,
            'transport_mode' => $this->transport_mode,
            'goods_country_code' => $this->goods_country_code,
            'origin_country_code' => $this->origin_country_code,
            'province_code' => $this->province_code,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date?->toDateString(),
            'service_code' => $this->service_code,
            'supply_mode' => $this->supply_mode,
            'payment_method' => $this->payment_method,
            'payment_country_code' => $this->payment_country_code,
            'corrected_month' => $this->corrected_month,
            'corrected_quarter' => $this->corrected_quarter,
            'corrected_year' => $this->corrected_year,
            'sign' => $this->sign,
            'corrected_customs_section' => $this->corrected_customs_section,
            'corrected_protocol_year' => $this->corrected_protocol_year,
            'corrected_protocol_number' => $this->corrected_protocol_number,
            'corrected_line_number' => $this->corrected_line_number,
            'is_cancellation' => $this->is_cancellation,
            'source_documents' => $this->source_documents ?? [],
        ];
    }
}
