<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum DiscrepancyStatus: string
{
    case PendingApproval = 'pending_approval';
    case ApprovedWriteOff = 'approved_write_off';
    case CarrierClaim = 'carrier_claim';
    case ReturnedToOrigin = 'returned_to_origin';

    /**
     * @return list<string>
     */
    public static function resolutions(): array
    {
        return [self::ApprovedWriteOff->value, self::CarrierClaim->value, self::ReturnedToOrigin->value];
    }
}
