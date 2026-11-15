<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Enums;

/**
 * Warranty coverage decision taken for an RMA at issue time.
 */
enum WarrantyStatus: string
{
    case CoveredInWarranty = 'covered_in_warranty';
    case OutOfWarrantyBillable = 'out_of_warranty_billable';
    case Goodwill = 'goodwill';

    /**
     * Covered and goodwill repairs are charged to the warranty provision, never to the customer.
     */
    public function isChargedToWarrantyProvision(): bool
    {
        return $this !== self::OutOfWarrantyBillable;
    }
}
