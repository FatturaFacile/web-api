<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Support;

use Modules\BondedWarehouses\Domain\Enums\DiscrepancyStatus;

/**
 * Thresholds of the AEO internal control.
 */
final class ComplianceClassifier
{
    public const float CGU_WARNING_PERCENT = 80.0;

    public const float CGU_CRITICAL_PERCENT = 95.0;

    /** A variance up to 1 % of the customs book is a minor one; above, or when the book is empty, it is reported to the authority. */
    public const float MINOR_VARIANCE_PERCENT = 1.0;

    public static function variance(float $physical, float $ledger): DiscrepancyStatus
    {
        $difference = abs(round($physical - $ledger, 4));

        if ($difference < 0.00005) {
            return DiscrepancyStatus::Matched;
        }

        if ($ledger > 0.0 && $difference / $ledger * 100 <= self::MINOR_VARIANCE_PERCENT) {
            return DiscrepancyStatus::MinorVariance;
        }

        return DiscrepancyStatus::CriticalAlertADMUDSC;
    }

    public static function cguThreshold(float $utilizationPercent): DiscrepancyStatus
    {
        return match (true) {
            $utilizationPercent >= self::CGU_CRITICAL_PERCENT => DiscrepancyStatus::CriticalAlertADMUDSC,
            $utilizationPercent >= self::CGU_WARNING_PERCENT => DiscrepancyStatus::MinorVariance,
            default => DiscrepancyStatus::Matched,
        };
    }
}
