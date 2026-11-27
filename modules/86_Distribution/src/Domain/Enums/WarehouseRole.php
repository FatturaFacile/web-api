<?php

declare(strict_types=1);

namespace Modules\Distribution\Domain\Enums;

use Modules\Distribution\Domain\Enums\Concerns\EnumValues;

enum WarehouseRole: string
{
    use EnumValues;

    case Internal = 'internal';
    case RetailPos = 'retail_pos';
    case Transit = 'transit';
    case ThirdParty3pl = 'third_party_3pl';
    case ConsignmentOut = 'consignment_out';
    case ConsignmentIn = 'consignment_in';

    public function isConsignment(): bool
    {
        return in_array($this, [self::ConsignmentOut, self::ConsignmentIn], true);
    }

    /** Roles that hold stock owned by the company (or consigned by it) and take part in planning and balancing. */
    public function isPlannable(): bool
    {
        return in_array($this, [self::Internal, self::RetailPos], true);
    }

    /** Roles whose stock is segregated from the free stock unless the profile says otherwise. */
    public function isSegregated(): bool
    {
        return in_array($this, [self::Transit, self::ThirdParty3pl, self::ConsignmentOut, self::ConsignmentIn], true);
    }
}
