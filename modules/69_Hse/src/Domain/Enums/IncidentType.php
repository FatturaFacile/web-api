<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Enums;

enum IncidentType: string
{
    case NearMiss = 'near_miss';
    case FirstAidInjury = 'first_aid_injury';
    case LostTimeAccident = 'lost_time_accident_inail_or_suva';
    case FatalOrSevere = 'fatal_or_severe';

    /**
     * Whether the incident must reach the insurer: a lost-time accident, a fatal one, or any injury whose prognosis
     * exceeds the statutory threshold of the jurisdiction.
     */
    public function isReportable(Jurisdiction $jurisdiction, int $prognosisDays): bool
    {
        return match ($this) {
            self::LostTimeAccident, self::FatalOrSevere => true,
            self::FirstAidInjury => $prognosisDays > $jurisdiction->lostTimeThresholdDays(),
            self::NearMiss => false,
        };
    }
}
