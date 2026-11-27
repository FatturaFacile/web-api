<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Domain\Enums;

/**
 * FullBom: the whole bill of materials is outsourced. SingleOperation: one routing step (heat treatment, painting,
 * galvanizing) is outsourced and the semi-finished goods travel out and back.
 */
enum SubcontractingType: string
{
    case FullBom = 'full_bom';
    case SingleOperation = 'single_operation';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
