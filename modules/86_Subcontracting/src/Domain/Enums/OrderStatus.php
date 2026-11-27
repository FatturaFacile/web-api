<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Domain\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case MaterialsDispatched = 'materials_dispatched';
    case InProgress = 'in_progress';
    case PartiallyReturned = 'partially_returned';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public static function open(): array
    {
        return [self::Confirmed, self::MaterialsDispatched, self::InProgress, self::PartiallyReturned];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
