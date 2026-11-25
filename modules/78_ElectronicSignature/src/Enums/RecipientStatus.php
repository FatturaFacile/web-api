<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Enums;

enum RecipientStatus: string
{
    /** Not their turn yet (sequential routing). */
    case Waiting = 'waiting';
    case Pending = 'pending';
    case Viewed = 'viewed';
    /** Signature requested at the provider and not confirmed yet (step-up on the phone of the signer). */
    case Signing = 'signing';
    case Signed = 'signed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    public function canAct(): bool
    {
        return in_array($this, [self::Pending, self::Viewed, self::Signing], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
