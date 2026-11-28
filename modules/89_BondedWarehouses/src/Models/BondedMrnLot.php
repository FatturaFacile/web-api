<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\BondedWarehouses\Domain\Enums\CustomsStatus;
use Modules\BondedWarehouses\Domain\Enums\DeclarationType;

/**
 * Certified bonded stock accounting lot: one MRN and declaration item (BondedStockAccountingLot_MRN).
 * remaining_* are the balances still under customs supervision; cgu_engaged_amount is the guarantee still engaged by the lot.
 *
 * @property string $authorization_id
 * @property string $mrn_number
 * @property int $mrn_item_number
 * @property DeclarationType $declaration_type
 * @property Carbon $entry_date
 * @property string $product_id
 * @property string|null $wms_lot_id
 * @property string $taric_hs_code
 * @property string $country_of_origin
 * @property bool $preferential_origin
 * @property string|null $preferential_agreement_code
 * @property string $initial_quantity
 * @property string $remaining_quantity
 * @property string $unit_of_measure
 * @property string $initial_net_weight_kg
 * @property string $remaining_net_weight_kg
 * @property string $initial_gross_weight_kg
 * @property string $remaining_gross_weight_kg
 * @property string $customs_statistical_value
 * @property string $duty_rate_percent
 * @property string $vat_rate_percent
 * @property string $suspended_duty_amount
 * @property string $suspended_vat_amount
 * @property string $cgu_engaged_amount
 * @property CustomsStatus $customs_status
 */
final class BondedMrnLot extends BaseEnterpriseModel
{
    protected $table = 'customs_bonded_mrn_lots';

    protected function casts(): array
    {
        return [
            'declaration_type' => DeclarationType::class,
            'customs_status' => CustomsStatus::class,
            'entry_date' => 'date',
            'preferential_origin' => 'boolean',
            'mrn_item_number' => 'integer',
            'initial_quantity' => 'decimal:4',
            'remaining_quantity' => 'decimal:4',
            'initial_net_weight_kg' => 'decimal:3',
            'remaining_net_weight_kg' => 'decimal:3',
            'initial_gross_weight_kg' => 'decimal:3',
            'remaining_gross_weight_kg' => 'decimal:3',
            'customs_statistical_value' => 'decimal:2',
            'duty_rate_percent' => 'decimal:4',
            'vat_rate_percent' => 'decimal:4',
            'suspended_duty_amount' => 'decimal:2',
            'suspended_vat_amount' => 'decimal:2',
            'cgu_engaged_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<CustomsWarehouseAuthorization, $this>
     */
    public function authorization(): BelongsTo
    {
        return $this->belongsTo(CustomsWarehouseAuthorization::class, 'authorization_id');
    }

    /**
     * @return HasMany<CustomsDischargeMovement, $this>
     */
    public function discharges(): HasMany
    {
        return $this->hasMany(CustomsDischargeMovement::class, 'mrn_lot_id')->orderBy('discharge_date')->orderBy('created_at');
    }
}
