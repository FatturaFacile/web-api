<?php

declare(strict_types=1);

namespace Modules\SwissVat\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Input tax adjustment under LIVA Art. 30-33 (Autoconsumo, Degrassazione, Mixed-use, Subsidy reduction).
 *
 * @property string|null $swiss_vat_return_id
 * @property string $adjustment_type
 * @property string $afc_box
 * @property CarbonImmutable $effective_date
 * @property int $fiscal_year
 * @property string|null $asset_category
 * @property string $description
 * @property string|null $reference_document
 * @property string|null $original_cost_chf
 * @property string|null $original_vat_rate
 * @property int|null $years_elapsed
 * @property string|null $private_or_exempt_ratio
 * @property string $adjustment_amount_chf
 * @property string $legal_basis
 * @property string $status
 * @property string|null $notes
 * @property string|null $approved_by
 */
final class SwissVatInputTaxAdjustment extends BaseEnterpriseModel
{
    protected $table = 'swiss_vat_input_tax_adjustments';

    protected function casts(): array
    {
        return [
            'effective_date' => 'immutable_date',
            'fiscal_year' => 'integer',
            'original_cost_chf' => 'decimal:2',
            'original_vat_rate' => 'decimal:2',
            'years_elapsed' => 'integer',
            'private_or_exempt_ratio' => 'decimal:2',
            'adjustment_amount_chf' => 'decimal:2',
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
