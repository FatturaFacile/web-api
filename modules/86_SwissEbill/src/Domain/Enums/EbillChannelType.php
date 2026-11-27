<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Enums;

enum EbillChannelType: string
{
    case B2cPrivate = 'b2c_private';
    case B2bEnterprise = 'b2b_enterprise';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
