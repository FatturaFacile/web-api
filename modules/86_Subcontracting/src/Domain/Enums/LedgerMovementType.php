<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Domain\Enums;

enum LedgerMovementType: string
{
    case OutboundDispatch = 'outbound_dispatch';
    case InboundConsumptionBackflush = 'inbound_consumption_backflush';
    case ScrapDeclared = 'scrap_declared';
    case UnusedMaterialReturn = 'unused_material_return';
    case CustomerMaterialReceipt = 'customer_material_receipt';
    case CustomerFinishedReturn = 'customer_finished_return';

    /** Movements that put goods into the third-party location and so start the one-year statutory clock. */
    public function startsStatutoryClock(): bool
    {
        return $this === self::OutboundDispatch || $this === self::CustomerMaterialReceipt;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
