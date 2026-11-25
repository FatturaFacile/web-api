<?php

declare(strict_types=1);

namespace Modules\Intercompany\Enums;

enum SyncEventType: string
{
    case MirrorCreated = 'mirror_created';
    case MirrorConfirmed = 'mirror_confirmed';
    case MirrorReversed = 'mirror_reversed';
    case PaymentSynced = 'payment_synced';
    case PaymentVariance = 'payment_variance';
    case NettingApplied = 'netting_applied';
    case Error = 'error';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
