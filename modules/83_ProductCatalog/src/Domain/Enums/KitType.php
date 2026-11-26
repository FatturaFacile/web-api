<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Domain\Enums;

/**
 * Phantom kit: sold as one line, exploded into its components for picking and the transport document, never stocked itself.
 * Physical kit: pre-assembled and stocked under its own SKU with its own cost.
 */
enum KitType: string
{
    use EnumValues;

    case Phantom = 'phantom';
    case Physical = 'physical';
}
