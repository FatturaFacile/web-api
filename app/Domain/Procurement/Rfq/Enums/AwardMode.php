<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\Enums;

/**
 * Award allocation mode supported by Mod 045.
 */
enum AwardMode: string
{
    case Full = 'full';
    case Split = 'split';
}
