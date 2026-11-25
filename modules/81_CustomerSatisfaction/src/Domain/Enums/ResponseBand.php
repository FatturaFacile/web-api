<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Domain\Enums;

use Modules\CustomerSatisfaction\Domain\Enums\Concerns\EnumValues;

enum ResponseBand: string
{
    use EnumValues;

    case Promoter = 'promoter';
    case Passive = 'passive';
    case Detractor = 'detractor';
    case Satisfied = 'satisfied';
    case Neutral = 'neutral';
    case Dissatisfied = 'dissatisfied';

    /**
     * Key of the dynamic follow-up question: the good, the middle and the bad answers.
     */
    public function promptKey(): string
    {
        return match ($this) {
            self::Promoter, self::Satisfied => 'high',
            self::Passive, self::Neutral => 'mid',
            self::Detractor, self::Dissatisfied => 'low',
        };
    }
}
