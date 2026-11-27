<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Enums;

enum EbillParticipantStatus: string
{
    case Unknown = 'unknown';
    case Enrolled = 'enrolled';
    case NotEnrolled = 'not_enrolled';
    case PendingOptIn = 'pending_opt_in';
    case Suspended = 'suspended';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function canReceive(): bool
    {
        return $this === self::Enrolled;
    }
}
