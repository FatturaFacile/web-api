<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Domain\Enums;

enum SplitMode: string
{
    case AdditionalRate = 'additional_rate';
    case ShareOfCommission = 'share_of_commission';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
