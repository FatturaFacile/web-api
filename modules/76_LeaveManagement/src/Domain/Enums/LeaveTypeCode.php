<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Domain\Enums;

/**
 * Canonical leave, permit, and absence types for Italy (IT) and Switzerland (CH).
 */
enum LeaveTypeCode: string
{
    case AnnualVacation_Ferie_Ferien = 'AnnualVacation_Ferie_Ferien';
    case IT_PermessiROL = 'IT_PermessiROL';
    case IT_ExFestivita = 'IT_ExFestivita';
    case SickLeave_Malattia = 'SickLeave_Malattia';
    case Accident_Infortunio_SUVA_INAIL = 'Accident_Infortunio_SUVA_INAIL';
    case MaternityPaternity_Congedo = 'MaternityPaternity_Congedo';
    case IT_Legge104_Permessi = 'IT_Legge104_Permessi';
    case CH_MilitaryCivilService_EO_APG = 'CH_MilitaryCivilService_EO_APG';
    case PaidSpecialLeave_MarriageBereavement = 'PaidSpecialLeave_MarriageBereavement';
    case UnpaidLeave = 'UnpaidLeave';

    public function consumesBalance(): bool
    {
        return match ($this) {
            self::AnnualVacation_Ferie_Ferien,
            self::IT_PermessiROL,
            self::IT_ExFestivita => true,
            default => false,
        };
    }

    public function countsTowardComporto(): bool
    {
        return $this === self::SickLeave_Malattia;
    }

    public function countsTowardSwissCurtailment(): bool
    {
        return match ($this) {
            self::SickLeave_Malattia,
            self::Accident_Infortunio_SUVA_INAIL,
            self::CH_MilitaryCivilService_EO_APG,
            self::UnpaidLeave => true,
            default => false,
        };
    }

    public function supportsInpsPuc(): bool
    {
        return $this === self::SickLeave_Malattia;
    }

    public function supportsSuvaOrInail(): bool
    {
        return $this === self::Accident_Infortunio_SUVA_INAIL;
    }

    public function defaultUnitMode(): LeaveUnitMode
    {
        return match ($this) {
            self::IT_PermessiROL,
            self::IT_ExFestivita,
            self::IT_Legge104_Permessi => LeaveUnitMode::HOURS,
            default => LeaveUnitMode::DAYS,
        };
    }
}
