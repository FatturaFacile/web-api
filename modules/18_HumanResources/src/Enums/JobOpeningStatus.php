<?php

declare(strict_types=1);

namespace Modules\HumanResources\Enums;

enum JobOpeningStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case InternalOnly = 'internal_only';
    case ClosedFilled = 'closed_filled';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Whether the opening accepts candidates and hires (published on the careers page or internal only).
     */
    public function isOpen(): bool
    {
        return $this === self::Published || $this === self::InternalOnly;
    }

    /**
     * Whether the opening reached a final state and can no longer be edited.
     */
    public function isClosed(): bool
    {
        return $this === self::ClosedFilled || $this === self::Cancelled;
    }
}
