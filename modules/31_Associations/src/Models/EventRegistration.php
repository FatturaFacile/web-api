<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ticket of an event for a person of the registry.
 */
final class EventRegistration extends AssociationRecord
{
    public const string Confirmed = 'confirmed';

    public const string Waitlisted = 'waitlisted';

    public const string Cancelled = 'cancelled';

    protected $table = 'assoc_event_registrations';

    protected function casts(): array
    {
        return ['waitlist_position' => 'integer', 'checked_in_at' => 'datetime'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'ticket_type_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
