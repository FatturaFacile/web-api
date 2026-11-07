<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

/**
 * The order stock leaves the warehouse: first expired first out, or first in first out.
 */
enum PickingStrategy: string
{
    case Fifo = 'fifo';
    case Fefo = 'fefo';
}
