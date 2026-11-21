<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Analytical data mart table `dim_cost_center` (Mod 093). Rebuilt from the operational data by the projectors, never edited by hand.
 */
final class DimCostCenter extends BaseEnterpriseModel
{
    protected $table = 'dim_cost_center';
}
