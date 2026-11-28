<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Enums;

enum AuditType: string
{
    case PhysicalBookReconciliation = 'Physical_Book_Reconciliation';
    case SecurityPartnerCheck = 'Security_Partner_Check';
    case UsualHandlingVerification = 'Usual_Handling_Verification';
    case CGUThresholdCheck = 'CGU_Threshold_Check';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
