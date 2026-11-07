<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A training a volunteer completed, with the date it has to be renewed.
 */
final class VolunteerTraining extends AssociationRecord
{
    protected $table = 'assoc_volunteer_trainings';

    protected function casts(): array
    {
        return ['completed_on' => 'date', 'expires_on' => 'date'];
    }

    public function volunteer(): BelongsTo
    {
        return $this->belongsTo(Volunteer::class, 'volunteer_id');
    }
}
