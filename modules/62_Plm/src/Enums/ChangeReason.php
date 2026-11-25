<?php

declare(strict_types=1);

namespace Modules\Plm\Enums;

enum ChangeReason: string
{
    case CostReduction = 'CostReduction';
    case QualitySafetyFix = 'QualitySafetyFix';
    case ComponentObsolescence = 'ComponentObsolescence';
    case CustomerRequest = 'CustomerRequest';
    case RegulatoryCompliance = 'RegulatoryCompliance';
    case ManufacturingOptimization = 'ManufacturingOptimization';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function requiresImmediateSafetyCutover(): bool
    {
        return $this === self::QualitySafetyFix;
    }
}
