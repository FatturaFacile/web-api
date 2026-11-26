<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Enums;

enum FxResult: string
{
    case Gain = 'gain';
    case Loss = 'loss';
    case Neutral = 'neutral';
}
