<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Enums;

use Carbon\CarbonImmutable;

/**
 * The safety certificates and licenses an employee can hold, with the statutory refresher cycle of each.
 */
enum CertificateType: string
{
    case GeneralSafety = 'general_safety_accordo_stato_regioni';
    case LowRiskSpecific = 'low_risk_specific';
    case MediumRiskSpecific = 'medium_risk_specific';
    case HighRiskSpecific = 'high_risk_specific';
    case Forklift = 'forklift_patentino_muletto';
    case CraneOperator = 'crane_operator_gru';
    case FirstAid = 'first_aid_primo_soccorso';
    case FireSafety = 'fire_safety_antincendio';
    case WorkAtHeight = 'work_at_height_lavori_in_quota';
    case ConfinedSpaces = 'confined_spaces';
    case SuvaPsaTraining = 'suva_psa_training';
    case RsppAslTraining = 'rspp_asl_training';
    case SiBeEkasTraining = 'sibe_ekas_training';

    /**
     * Years until the refresher is due, or null when the module does not expire (the 12 hour general module).
     */
    public function refresherYears(Jurisdiction $jurisdiction): ?int
    {
        return match ($this) {
            self::GeneralSafety => null,
            self::FirstAid, self::FireSafety => $jurisdiction === Jurisdiction::Italy ? 3 : 2,
            self::Forklift, self::CraneOperator => $jurisdiction === Jurisdiction::Italy ? 5 : 3,
            default => 5,
        };
    }

    /**
     * The Italian first aid and fire courses of the specific risk classes recur every 2 years in the calendar of the
     * module; this keeps the Accordo Stato-Regioni five-year default for every other course.
     */
    public function nextRefresher(CarbonImmutable $issuedAt, Jurisdiction $jurisdiction, bool $specificRiskClass = false): ?CarbonImmutable
    {
        $years = $this->refresherYears($jurisdiction);

        if ($years !== null && $specificRiskClass && in_array($this, [self::FirstAid, self::FireSafety], true)) {
            $years = 2;
        }

        return $years === null ? null : $issuedAt->addYears($years);
    }
}
