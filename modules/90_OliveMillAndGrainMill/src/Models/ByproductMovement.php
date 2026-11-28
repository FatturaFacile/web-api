<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\OliveMillAndGrainMill\Domain\Enums\ByproductType;

/**
 * Traceability register of the by-products: virgin pomace, olive pits, vegetation water spread on land and bran.
 *
 * @property ByproductType $byproduct_type
 * @property Carbon $movement_date
 * @property string $quantity
 * @property string $unit
 * @property string|null $delivery_id
 * @property string|null $cycle_id
 * @property string|null $counterparty_contact_id
 * @property string|null $counterparty_name
 * @property string|null $destination_type
 * @property string|null $transport_document
 * @property string|null $cadastral_parcel
 * @property string|null $parcel_area_ha
 * @property string|null $m3_per_ha
 * @property string|null $notes
 * @property string|null $created_by
 */
final class ByproductMovement extends BaseEnterpriseModel
{
    protected $table = 'mill_byproduct_movements';

    protected function casts(): array
    {
        return [
            'byproduct_type' => ByproductType::class,
            'movement_date' => 'date',
            'quantity' => 'decimal:3',
            'parcel_area_ha' => 'decimal:4',
            'm3_per_ha' => 'decimal:3',
        ];
    }
}
