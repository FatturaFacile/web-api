<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Associations\Enums\AttendanceStatus;

/**
 * A person of the registry called to a meeting: whether it can vote, how it attended and who holds its proxy.
 *
 * @property string $id
 * @property string $meeting_id
 * @property string $member_id
 * @property bool $is_eligible_voter
 * @property AttendanceStatus $attendance
 * @property ?string $proxy_holder_id
 * @property-read Member $member
 * @property-read Meeting $meeting
 */
final class MeetingParticipant extends AssociationRecord
{
    protected $table = 'assoc_meeting_participants';

    protected function casts(): array
    {
        return ['is_eligible_voter' => 'boolean', 'attendance' => AttendanceStatus::class, 'attendance_recorded_at' => 'datetime'];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function proxyHolder(): BelongsTo
    {
        return $this->belongsTo(self::class, 'proxy_holder_id');
    }

    public function isAttending(): bool
    {
        return in_array($this->attendance, [AttendanceStatus::Present, AttendanceStatus::Online], true);
    }
}
