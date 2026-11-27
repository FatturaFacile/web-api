<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Enums;

/**
 * Lifecycle of a seasonal maintenance contract.
 */
enum ContractStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';
    case Renewed = 'renewed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
