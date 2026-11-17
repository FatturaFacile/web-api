<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Extended care pack attached to an equipment passport.
 *
 * @property string $id
 * @property string $equipment_passport_id
 * @property string $name
 * @property int $months
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $reference
 * @property string $price
 * @property string $currency
 */
final class WarrantyExtension extends BaseEnterpriseModel
{
    protected $table = 'esm_equipment_warranty_extensions';

    protected function casts(): array
    {
        return [
            'months' => 'integer',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'price' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<SerializedEquipmentPassport, $this>
     */
    public function passport(): BelongsTo
    {
        return $this->belongsTo(SerializedEquipmentPassport::class, 'equipment_passport_id');
    }
}
