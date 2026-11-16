<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

/**
 * Checks Swiss LL/OLL 1 working-time compliance rules (Art. 15 LL mandatory breaks).
 */
final class SwitzerlandComplianceCalculator
{
    // Art. 15 LL mandatory break thresholds
    private const BREAK_THRESHOLDS = [
        ['after_minutes' => 540, 'required_minutes' => 60],  // >9h → 1h break
        ['after_minutes' => 420, 'required_minutes' => 30],  // >7h → 30min break
        ['after_minutes' => 330, 'required_minutes' => 15],  // >5.5h → 15min break
    ];

    /**
     * Find days where mandatory breaks were not taken per Art. 15 LL.
     *
     * @param  array<object>  $dailyRecords  stdClass with: user_id, work_date, worked_minutes, break_minutes (nullable)
     * @return array<array{user_id: string, date: string, type: string, worked_hours: float, required_break_minutes: int, actual_break_minutes: int}>
     */
    public function checkDailyBreaks(array $dailyRecords): array
    {
        $violations = [];

        foreach ($dailyRecords as $record) {
            $workedMinutes = (int) $record->worked_minutes;
            $breakMinutes = (int) ($record->break_minutes ?? 0);
            $requiredBreak = $this->requiredBreakMinutes($workedMinutes);

            if ($requiredBreak > 0 && $breakMinutes < $requiredBreak) {
                $violations[] = [
                    'user_id' => (string) $record->user_id,
                    'date' => $record->work_date,
                    'type' => 'break_missing',
                    'worked_hours' => round($workedMinutes / 60, 2),
                    'required_break_minutes' => $requiredBreak,
                    'actual_break_minutes' => $breakMinutes,
                ];
            }
        }

        return $violations;
    }

    /**
     * Determine required break duration in minutes for a given number of worked minutes.
     */
    private function requiredBreakMinutes(int $workedMinutes): int
    {
        foreach (self::BREAK_THRESHOLDS as $threshold) {
            if ($workedMinutes > $threshold['after_minutes']) {
                return $threshold['required_minutes'];
            }
        }

        return 0;
    }
}
