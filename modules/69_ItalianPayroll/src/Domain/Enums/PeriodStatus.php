<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Domain\Enums;

/**
 * The closing workflow of a payroll month. The order is the order of the workflow: attendance can change only while the
 * period is still open, and a state can only advance to the next one (a reopening goes through an explicit command).
 */
enum PeriodStatus: string
{
    case AttendanceOpen = 'attendance_open';
    case Validated = 'validated';
    case ExportedToCdl = 'exported_to_cdl';
    case CedoliniImported = 'cedolini_imported';
    case Paid = 'paid';

    public function rank(): int
    {
        return match ($this) {
            self::AttendanceOpen => 0,
            self::Validated => 1,
            self::ExportedToCdl => 2,
            self::CedoliniImported => 3,
            self::Paid => 4,
        };
    }

    public function next(): ?self
    {
        return match ($this) {
            self::AttendanceOpen => self::Validated,
            self::Validated => self::ExportedToCdl,
            self::ExportedToCdl => self::CedoliniImported,
            self::CedoliniImported => self::Paid,
            self::Paid => null,
        };
    }

    public function canAdvanceTo(self $target): bool
    {
        return $this->next() === $target;
    }

    public function allowsAttendanceChanges(): bool
    {
        return $this === self::AttendanceOpen;
    }
}
