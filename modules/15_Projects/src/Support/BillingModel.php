<?php

declare(strict_types=1);

namespace Modules\Projects\Support;

/**
 * How a project is billed to the customer (Mod 081).
 */
enum BillingModel: string
{
    case TimeAndMaterials = 'time_and_materials';
    case FixedMilestone = 'fixed_milestone';
    case PrepaidRetainer = 'prepaid_retainer';
    case SalProgress = 'sal_progress';
    case Hybrid = 'hybrid';

    public function billsTimeAndMaterials(): bool
    {
        return in_array($this, [self::TimeAndMaterials, self::PrepaidRetainer, self::Hybrid], true);
    }

    public function billsMilestones(): bool
    {
        return in_array($this, [self::FixedMilestone, self::Hybrid], true);
    }

    public function billsProgress(): bool
    {
        return in_array($this, [self::SalProgress, self::Hybrid], true);
    }

    public function usesRetainer(): bool
    {
        return in_array($this, [self::PrepaidRetainer, self::Hybrid], true);
    }
}
