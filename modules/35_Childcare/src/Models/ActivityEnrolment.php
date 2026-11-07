<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A child enrolled in an activity for a period.
 */
final class ActivityEnrolment extends ChildcareRecord
{
    protected $table = 'cc_activity_enrolments';

    protected function casts(): array
    {
        return ['valid_from' => 'immutable_date', 'valid_to' => 'immutable_date'];
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'activity_id');
    }
}
