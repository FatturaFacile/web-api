<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Spacing profile of the web interface: compact for data entry, spacious for touch and accessibility.
 */
enum UiDensity: string
{
    case Compact = 'compact';
    case Comfortable = 'comfortable';
    case Spacious = 'spacious';
}
