<?php

declare(strict_types=1);

namespace Modules\HumanResources\Enums\Payroll;

/**
 * Codes of the calculated (non wage-type) payslip lines. The rates behind each code come from the rate set.
 */
enum ContributionCode: string
{
    case AhvIvEo = 'AHV_IV_EO';
    case AhvAdminCost = 'AHV_ADMIN';
    case Alv = 'ALV';
    case AlvSolidarity = 'ALV_SOLIDARITY';
    case Bvg = 'BVG';
    case UvgBu = 'UVG_BU';
    case UvgNbu = 'UVG_NBU';
    case Ktg = 'KTG';
    case Fak = 'FAK';
    case WithholdingTax = 'QST';

    public function label(): string
    {
        return match ($this) {
            self::AhvIvEo => 'AHV/IV/EO',
            self::AhvAdminCost => 'AHV administration costs',
            self::Alv => 'ALV',
            self::AlvSolidarity => 'ALV solidarity',
            self::Bvg => 'BVG pension fund',
            self::UvgBu => 'UVG occupational accident (BU)',
            self::UvgNbu => 'UVG non-occupational accident (NBU)',
            self::Ktg => 'KTG daily sickness allowance',
            self::Fak => 'FAK family compensation fund',
            self::WithholdingTax => 'Withholding tax (Quellensteuer)',
        };
    }
}
