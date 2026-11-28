<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\BondedWarehouses\Domain\Concerns\ImmutableRecord;
use Modules\BondedWarehouses\Domain\Enums\AllocationMethod;
use Modules\BondedWarehouses\Domain\Enums\DischargeType;

/**
 * Immutable audit trail of a discharge, clearance, re-export, transit or usual handling operation on an MRN lot.
 *
 * @property string $authorization_id
 * @property string $mrn_lot_id
 * @property DischargeType $discharge_type
 * @property string|null $discharge_mrn_number
 * @property Carbon $discharge_date
 * @property AllocationMethod $allocation_method
 * @property string $discharged_quantity
 * @property string $discharged_net_weight_kg
 * @property string $released_customs_value
 * @property string $paid_duty_amount
 * @property string $paid_vat_amount
 * @property string $extinguished_duty_amount
 * @property string $extinguished_vat_amount
 * @property string $cgu_released_amount
 * @property string|null $usual_handling_code
 * @property string|null $usual_handling_notes
 * @property string|null $customs_prospectus_document_id
 * @property string|null $created_by
 */
final class CustomsDischargeMovement extends BaseEnterpriseModel
{
    use ImmutableRecord;

    protected $table = 'customs_discharge_movements';

    protected function casts(): array
    {
        return [
            'discharge_type' => DischargeType::class,
            'allocation_method' => AllocationMethod::class,
            'discharge_date' => 'date',
            'discharged_quantity' => 'decimal:4',
            'discharged_net_weight_kg' => 'decimal:3',
            'released_customs_value' => 'decimal:2',
            'paid_duty_amount' => 'decimal:2',
            'paid_vat_amount' => 'decimal:2',
            'extinguished_duty_amount' => 'decimal:2',
            'extinguished_vat_amount' => 'decimal:2',
            'cgu_released_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<BondedMrnLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(BondedMrnLot::class, 'mrn_lot_id');
    }
}
