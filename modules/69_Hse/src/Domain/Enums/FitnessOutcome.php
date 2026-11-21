<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Enums;

/**
 * The judgment of the occupational physician (Giudizio di Idoneità / Arbeitsmedizinische Beurteilung). Only this
 * judgment and its operational prescriptions are shared; diagnoses never are.
 */
enum FitnessOutcome: string
{
    case Fit = 'fit_idoneo';
    case FitWithPrescriptions = 'fit_with_prescriptions_idoneo_con_prescrizioni';
    case TemporarilyUnfit = 'temporarily_unfit_inidoneo_temporaneo';
    case PermanentlyUnfit = 'permanently_unfit_inidoneo_permanente';

    public function blocksDispatch(): bool
    {
        return $this === self::TemporarilyUnfit || $this === self::PermanentlyUnfit;
    }

    public function needsRestrictionReview(): bool
    {
        return $this !== self::Fit;
    }
}
