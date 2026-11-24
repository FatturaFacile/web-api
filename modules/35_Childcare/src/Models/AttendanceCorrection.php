<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

/**
 * An authorised change of an attendance: the previous and new value are kept, encrypted, and never changed afterwards.
 */
final class AttendanceCorrection extends ChildcareRecord
{
    public const UPDATED_AT = null;

    protected $table = 'cc_attendance_corrections';

    protected static function booted(): void
    {
        self::updating(static fn (): bool => false);
        self::deleting(static fn (): bool => false);
    }

    protected function casts(): array
    {
        return ['old_value' => 'encrypted', 'new_value' => 'encrypted'];
    }
}
