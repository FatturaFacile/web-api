<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

/**
 * The order stock leaves the warehouse: first in first out, first expired first out, or last in first out (bulk
 * goods stacked so that the newest is on top, e.g. sand, gravel, stacked panels).
 */
enum PickingStrategy: string
{
    case Fifo = 'fifo';
    case Fefo = 'fefo';
    case Lifo = 'lifo';
}
