<?php

declare(strict_types=1);

namespace Modules\Intrastat\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A detail row of an INTRA list. Which columns apply depends on the model and the section:
 *
 * - section 1: INTRA-1 bis (cessioni di beni) or INTRA-2 bis (acquisti di beni);
 * - section 2: INTRA-1 ter / INTRA-2 ter, corrections of section 1 of earlier periods;
 * - section 3: INTRA-1 quater (servizi resi) or INTRA-2 quater (servizi ricevuti);
 * - section 4: INTRA-1 quinquies / INTRA-2 quinquies, corrections of section 3 of earlier lists.
 *
 * `goods_country_code` is the country of destination on sales and of provenance on purchases; `province_code` the
 * province of origin on sales and of destination on purchases.
 *
 * @property string $id
 * @property string $declaration_id
 * @property int $section
 * @property int $line_number
 * @property string|null $partner_country_code
 * @property string|null $partner_vat_number
 * @property int|null $amount_eur
 * @property int|null $amount_currency
 * @property string|null $transaction_nature_a
 * @property string|null $transaction_nature_b
 * @property string|null $cn8_code
 * @property int|null $net_mass_kg
 * @property int|null $supplementary_units
 * @property int|null $statistical_value_eur
 * @property string|null $delivery_terms
 * @property string|null $transport_mode
 * @property string|null $goods_country_code
 * @property string|null $origin_country_code
 * @property string|null $province_code
 * @property string|null $invoice_number
 * @property Carbon|null $invoice_date
 * @property string|null $service_code
 * @property string|null $supply_mode
 * @property string|null $payment_method
 * @property string|null $payment_country_code
 * @property int|null $corrected_month
 * @property int|null $corrected_quarter
 * @property int|null $corrected_year
 * @property string|null $sign
 * @property string|null $corrected_customs_section
 * @property int|null $corrected_protocol_year
 * @property string|null $corrected_protocol_number
 * @property int|null $corrected_line_number
 * @property bool $is_cancellation
 * @property list<array<string, mixed>>|null $source_documents
 */
final class IntrastatDeclarationLine extends IntrastatRecord
{
    protected $table = 'intrastat_declaration_lines';

    protected function casts(): array
    {
        return [
            'section' => 'integer',
            'line_number' => 'integer',
            'amount_eur' => 'integer',
            'amount_currency' => 'integer',
            'net_mass_kg' => 'integer',
            'supplementary_units' => 'integer',
            'statistical_value_eur' => 'integer',
            'invoice_date' => 'date',
            'corrected_month' => 'integer',
            'corrected_quarter' => 'integer',
            'corrected_year' => 'integer',
            'corrected_protocol_year' => 'integer',
            'corrected_line_number' => 'integer',
            'is_cancellation' => 'boolean',
            'source_documents' => 'array',
        ];
    }

    /**
     * @return BelongsTo<IntrastatDeclaration, $this>
     */
    public function declaration(): BelongsTo
    {
        return $this->belongsTo(IntrastatDeclaration::class, 'declaration_id');
    }
}
