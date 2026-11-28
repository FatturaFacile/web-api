<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Services;

use Modules\Beekeeping\Domain\ValueObjects\QueenMarking;

/**
 * Automated colony alerts: swarming risk (allerta sciamatura), queenlessness (orfanità), weak colony and aged queen.
 * Codes map to the frontend keys beekeeping.alert.<code>.
 */
final class ColonyAlertService
{
    /**
     * @param  array{brood_frames: int, supers_count: int, strength_score: int|null, swarm_cells_found?: int, swarm_cells_destroyed?: int, queen_seen?: bool, eggs_present?: bool, larvae_present?: bool, queen_status?: string|null, queen_year?: int|null}  $facts
     * @return list<string>
     */
    public function alerts(array $facts, ?int $currentYear = null): array
    {
        $alerts = [];
        $cellsLeft = max(0, ($facts['swarm_cells_found'] ?? 0) - ($facts['swarm_cells_destroyed'] ?? 0));
        $crowded = $facts['brood_frames'] >= (int) config('beekeeping.alerts.swarm_brood_frames') && $facts['supers_count'] === 0;

        if ($cellsLeft >= (int) config('beekeeping.alerts.swarm_cells_threshold') || $crowded) {
            $alerts[] = 'swarming_risk';
        }

        $noBrood = array_key_exists('eggs_present', $facts) && ! $facts['eggs_present'] && ! ($facts['larvae_present'] ?? false) && ! ($facts['queen_seen'] ?? false);

        if (($facts['queen_status'] ?? null) === 'missing' || $noBrood) {
            $alerts[] = 'queenless';
        }

        if ($facts['strength_score'] !== null && $facts['strength_score'] <= (int) config('beekeeping.alerts.weak_strength_score')) {
            $alerts[] = 'weak_colony';
        }

        $queenYear = $facts['queen_year'] ?? null;

        if ($queenYear !== null && QueenMarking::ageInYears($queenYear, $currentYear ?? (int) date('Y')) >= 3) {
            $alerts[] = 'old_queen';
        }

        return $alerts;
    }
}
