<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A point of the agenda. A point linked to an enrolment application carries the decision of the board on it.
 *
 * @property string $id
 * @property string $meeting_id
 * @property ?string $application_id
 * @property ?string $decision
 * @property-read Meeting $meeting
 */
final class MeetingAgendaItem extends AssociationRecord
{
    protected $table = 'assoc_meeting_agenda_items';

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(EnrolmentApplication::class, 'application_id');
    }
}
