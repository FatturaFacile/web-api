<?php

declare(strict_types=1);

namespace Modules\Distribution\Domain\Enums;

use Modules\Distribution\Domain\Enums\Concerns\EnumValues;

enum BucketType: string
{
    use EnumValues;

    case Daily = 'daily';
    case Weekly = 'weekly';

    public function days(): int
    {
        return match ($this) {
            self::Daily => 1,
            self::Weekly => 7,
        };
    }
}
