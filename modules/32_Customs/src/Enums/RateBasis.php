<?php

declare(strict_types=1);

namespace Modules\Customs\Enums;

enum RateBasis: string
{
    case Flat = 'flat';
    case PerKg = 'per_kg';
    case PerM3 = 'per_m3';
}
