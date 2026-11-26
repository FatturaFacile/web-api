<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Domain\Enums;

/**
 * Shared helper of the backed enums of the catalog.
 */
trait EnumValues
{
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
