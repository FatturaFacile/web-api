<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Enums;

/**
 * How a routing operation is carried out.
 */
enum OperationType: string
{
    case InternalMachining = 'internal_machining';
    case ManualAssembly = 'manual_assembly';
    case ExternalSubcontracting = 'external_subcontracting';

    /**
     * Whether the operation is bought from a vendor (Mod 065) instead of run on a work centre.
     */
    public function isSubcontracted(): bool
    {
        return $this === self::ExternalSubcontracting;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
