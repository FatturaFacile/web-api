<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Domain\Enums;

enum ParticipationStatus: string
{
    case Scouting = 'Scouting';
    case GoApproved = 'GoApproved';
    case PreparingEnvelopes = 'PreparingEnvelopes';
    case Submitted = 'Submitted';
    case AwardedWon = 'AwardedWon';
    case Lost = 'Lost';
    case LegalAppeal = 'LegalAppeal_Ricorso';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Statuses reachable from this one.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Scouting => [self::GoApproved, self::Lost],
            self::GoApproved => [self::PreparingEnvelopes, self::Scouting, self::Lost],
            self::PreparingEnvelopes => [self::Submitted, self::GoApproved, self::Lost],
            self::Submitted => [self::AwardedWon, self::Lost],
            self::Lost => [self::LegalAppeal],
            self::LegalAppeal => [self::AwardedWon, self::Lost],
            self::AwardedWon => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->transitions(), true);
    }
}
