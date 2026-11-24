<?php

declare(strict_types=1);

namespace Modules\Procurement\Enums;

/**
 * Qualification (onboarding) status of a supplier. Only qualified and conditionally qualified suppliers can receive
 * purchase orders; a mandatory document past its expiry date suspends the qualification until it is renewed.
 */
enum SupplierQualificationStatus: string
{
    case Prospect = 'prospect';
    case InQualification = 'in_qualification';
    case Qualified = 'qualified';
    case Conditional = 'conditional';
    case Expired = 'expired';
    case Disqualified = 'disqualified';

    /**
     * Whether purchase orders can be issued to a supplier in this status.
     */
    public function canOrder(): bool
    {
        return in_array($this, [self::Qualified, self::Conditional], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
