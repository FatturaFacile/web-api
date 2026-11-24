<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whether a volunteer is available for a shift.
 */
final class ShiftAvailability extends AssociationEntry
{
    protected $table = 'assoc_shift_availabilities';

    protected function casts(): array
    {
        return ['is_available' => 'boolean'];
    }

    public function volunteer(): BelongsTo
    {
        return $this->belongsTo(Volunteer::class, 'volunteer_id');
    }
}
