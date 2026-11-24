<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A volunteer on a shift. A volunteer who cannot come asks for a substitute; once replaced the assignment no longer
 * counts towards the coverage.
 *
 * @property string $id
 * @property string $shift_id
 * @property string $volunteer_id
 * @property string $status
 * @property-read Shift $shift
 * @property-read Volunteer $volunteer
 */
final class ShiftAssignment extends AssociationRecord
{
    public const string Assigned = 'assigned';

    public const string SubstitutionRequested = 'substitution_requested';

    public const string Replaced = 'replaced';

    /**
     * Statuses that cover a place on the shift.
     */
    public const array Covering = [self::Assigned, self::SubstitutionRequested];

    protected $table = 'assoc_shift_assignments';

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function volunteer(): BelongsTo
    {
        return $this->belongsTo(Volunteer::class, 'volunteer_id');
    }
}
