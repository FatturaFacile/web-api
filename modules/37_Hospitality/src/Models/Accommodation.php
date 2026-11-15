<?php

declare(strict_types=1);

namespace Modules\Hospitality\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A physical hotel room. It is available or out of service, and clean, dirty or inspected for housekeeping.
 *
 * @property string $id
 * @property string $room_type_id
 * @property string $number
 * @property string|null $floor
 * @property string $status
 * @property string $housekeeping_status
 */
final class Accommodation extends HospitalityRecord
{
    public const array Statuses = ['available', 'out_of_service'];

    public const array HousekeepingStatuses = ['clean', 'dirty', 'inspected'];

    protected $table = 'hosp_accommodations';

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }
}
