<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A transhumance (nomadism) movement or a sale / purchase of hives, swarms, nuclei or queens, with its accompanying document number.
 *
 * @property string $document_number
 * @property string $kind
 * @property string $origin_apiary_id
 * @property string|null $destination_apiary_id
 * @property string|null $destination_label
 * @property string|null $destination_registry_code
 * @property string|null $counterpart
 * @property string|null $target_flora
 * @property string $subject
 * @property int $quantity
 * @property string|null $vehicle_plate
 * @property Carbon $departure_date
 * @property Carbon|null $arrival_date
 * @property Carbon|null $return_date
 * @property string $status
 * @property string|null $notes
 */
final class NomadismMovement extends BaseEnterpriseModel
{
    protected $table = 'bee_nomadism_movements';

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'departure_date' => 'date',
            'arrival_date' => 'date',
            'return_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Apiary, $this>
     */
    public function origin(): BelongsTo
    {
        return $this->belongsTo(Apiary::class, 'origin_apiary_id');
    }

    /**
     * @return BelongsTo<Apiary, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Apiary::class, 'destination_apiary_id');
    }
}
