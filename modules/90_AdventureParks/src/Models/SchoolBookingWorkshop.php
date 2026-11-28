<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workshop of a school trip with the students attending it and the price per student at the time of the booking.
 *
 * @property string $school_booking_id
 * @property string $workshop_id
 * @property int $quantity_students
 * @property string $unit_price
 */
final class SchoolBookingWorkshop extends BaseEnterpriseModel
{
    protected $table = 'adv_school_booking_workshops';

    protected function casts(): array
    {
        return [
            'quantity_students' => 'integer',
            'unit_price' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<EducationalWorkshop, $this>
     */
    public function workshop(): BelongsTo
    {
        return $this->belongsTo(EducationalWorkshop::class, 'workshop_id')->withoutGlobalScopes();
    }
}
