<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Analytical data mart table `agg_executive_daily_snapshots` (Mod 093). Rebuilt from the operational data by the projectors, never edited by hand.
 */
final class AggExecutiveDailySnapshot extends BaseEnterpriseModel
{
    protected $table = 'agg_executive_daily_snapshots';

    protected function casts(): array
    {
        return ['snapshot_date' => 'date'];
    }
}
