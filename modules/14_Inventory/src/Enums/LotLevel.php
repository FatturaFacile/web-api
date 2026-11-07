<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

/**
 * The two levels of a lot: what arrives or is processed, and what leaves packaged.
 */
enum LotLevel: string
{
    case RawMaterial = 'raw_material';
    case Packaged = 'packaged';
}
