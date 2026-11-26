<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Domain\Enums;

/**
 * How a variant surcharge is read: an amount added to the parent figure or a percentage of it.
 */
enum DeltaType: string
{
    use EnumValues;

    case Fixed = 'fixed';
    case Percent = 'percent';
}
