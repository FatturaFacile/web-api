<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Services;

use Illuminate\Support\Carbon;
use Modules\GreenCare\Models\SeasonalMaintenanceContract;

/**
 * Tells whether a contract needs a visit in a week. A contract with included passages is due when the passages that the season elapsed
 * at the end of the week asks for (the included passages spread evenly over the season) are not yet done or already planned; a contract
 * without included passages (per passage, time and material) is visited every week of its season.
 */
final class DueInterventionPlanner
{
    /**
     * @param  int  $pendingStops  planned stops of the contract that are not completed yet
     */
    public function isDue(SeasonalMaintenanceContract $contract, Carbon $weekStart, int $pendingStops): bool
    {
        $weekEnd = $weekStart->copy()->addDays(6);

        if ($contract->season_end_date->lt($weekStart) || $contract->season_start_date->gt($weekEnd)) {
            return false;
        }

        if ($pendingStops > 0) {
            return false;
        }

        $included = $contract->included_passages_count;

        if ($included <= 0) {
            return true;
        }

        $seasonDays = max(1, (int) $contract->season_start_date->diffInDays($contract->season_end_date) + 1);
        $asOf = $weekEnd->lt($contract->season_end_date) ? $weekEnd : $contract->season_end_date;
        $elapsed = max(1, (int) $contract->season_start_date->diffInDays($asOf) + 1);
        $expected = (int) ceil($included * $elapsed / $seasonDays);

        return $expected - $contract->completed_passages_count > 0;
    }
}
