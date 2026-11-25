<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Enums;

/**
 * draft ─► pending ─► partially_signed ─► completed
 *            └──────────┴──► declined | expired | cancelled
 */
enum EnvelopeStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case PartiallySigned = 'partially_signed';
    case Completed = 'completed';
    case Declined = 'declined';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * Whether recipients can still sign.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::PartiallySigned;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Declined, self::Expired, self::Cancelled], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
