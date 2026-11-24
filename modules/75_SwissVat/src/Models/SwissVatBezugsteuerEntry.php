<?php

declare(strict_types=1);

namespace Modules\SwissVat\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foreign service acquisition subject to Swiss Bezugsteuer (Art. 45 LIVA -> AFC Box 382/383).
 *
 * @property string|null $swiss_vat_return_id
 * @property string|null $source_document_type
 * @property string|null $source_document_id
 * @property string|null $supplier_id
 * @property string $supplier_name
 * @property string $supplier_country
 * @property string|null $supplier_vat_number
 * @property string|null $invoice_number
 * @property CarbonImmutable $invoice_date
 * @property string $service_category
 * @property string $description
 * @property string $currency
 * @property string $original_net_amount
 * @property string $exchange_rate
 * @property string $net_amount_chf
 * @property string $vat_rate_percent
 * @property string $tax_amount_chf
 * @property string $deductible_input_tax_chf
 * @property string $afc_box
 * @property string $detection_source
 * @property string|null $detection_reason
 * @property string $status
 * @property string|null $confirmed_by
 * @property CarbonImmutable|null $confirmed_at
 */
final class SwissVatBezugsteuerEntry extends BaseEnterpriseModel
{
    protected $table = 'swiss_vat_bezugsteuer_entries';

    protected function casts(): array
    {
        return [
            'invoice_date' => 'immutable_date',
            'original_net_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'net_amount_chf' => 'decimal:2',
            'vat_rate_percent' => 'decimal:2',
            'tax_amount_chf' => 'decimal:2',
            'deductible_input_tax_chf' => 'decimal:2',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SwissVatReturn, $this>
     */
    public function vatReturn(): BelongsTo
    {
        return $this->belongsTo(SwissVatReturn::class, 'swiss_vat_return_id');
    }
}
