<?php

declare(strict_types=1);

namespace Modules\HumanResources\Enums;

/**
 * Why an employee leaves. Voluntary reasons start from the employee, the others from the company or from the contract.
 */
enum ExitReasonCode: string
{
    case ResignationBetterOffer = 'resignation_better_offer';
    case ResignationCareerChange = 'resignation_career_change';
    case ResignationRelocation = 'resignation_relocation';
    case ResignationPersonal = 'resignation_personal';
    case DismissalPerformance = 'dismissal_performance';
    case DismissalRedundancy = 'dismissal_redundancy';
    case MutualAgreement = 'mutual_agreement';
    case ContractEnd = 'contract_end';
    case Retirement = 'retirement';
    case Other = 'other';

    public function isVoluntary(): bool
    {
        return in_array($this, [self::ResignationBetterOffer, self::ResignationCareerChange, self::ResignationRelocation, self::ResignationPersonal], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
