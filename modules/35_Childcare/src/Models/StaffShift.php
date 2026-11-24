<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

/**
 * Hours worked by a member of the staff on a day.
 *
 * @property string $user_id
 * @property string $starts_at
 * @property string $ends_at
 * @property int $break_minutes
 */
final class StaffShift extends ChildcareRecord
{
    protected $table = 'cc_staff_shifts';

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'break_minutes' => 'integer'];
    }

    /**
     * Minutes worked in the shift, less the unpaid break.
     */
    public function workedMinutes(): int
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', $this->starts_at));
        [$endHour, $endMinute] = array_map('intval', explode(':', $this->ends_at));

        return max(0, ($endHour * 60 + $endMinute) - ($startHour * 60 + $startMinute) - $this->break_minutes);
    }
}
