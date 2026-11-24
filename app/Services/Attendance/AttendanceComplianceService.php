<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AttendanceComplianceService
{
    /**
     * Verify compliance with Italian Legislative Decree D.Lgs. 66/2003:
     * - Minimum 11 consecutive hours of daily rest between shifts (Art. 7)
     * - Maximum 48-hour weekly work limit average (Art. 4)
     *
     * @return array{
     *     compliant: bool,
     *     violations: array<int, array{rule: string, message: string, entry_id?: string, date: string}>,
     *     stats: array<string, mixed>
     * }
     */
    public function checkItalianCompliance(User $user, Carbon $startDate, Carbon $endDate): array
    {
        $entries = TimeEntry::where('user_id', $user->id)
            ->whereBetween('clock_in', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->orderBy('clock_in', 'asc')
            ->get();

        $violations = [];
        $totalHoursWorked = 0.0;
        $previousClockOut = null;

        foreach ($entries as $entry) {
            $clockIn = Carbon::parse($entry->clock_in);
            $clockOut = $entry->clock_out ? Carbon::parse($entry->clock_out) : null;

            if ($clockOut !== null) {
                $durationHours = max(0, (abs($clockOut->diffInMinutes($clockIn)) - ($entry->break_minutes ?? 0)) / 60.0);
                $totalHoursWorked += $durationHours;
            }

            // Check 11 consecutive hours of daily rest between consecutive shifts
            if ($previousClockOut !== null) {
                $restHours = abs($clockIn->diffInMinutes($previousClockOut)) / 60.0;
                if ($restHours < 11.0) {
                    $violations[] = [
                        'rule' => 'IT_D_LGS_66_2003_ART_7',
                        'message' => sprintf(
                            'Riposo giornaliero insufficiente: solo %.2f ore registrate tra i turni (minimo obbligatorio 11 ore consecutive).',
                            $restHours
                        ),
                        'entry_id' => $entry->id,
                        'date' => $clockIn->toDateString(),
                    ];
                }
            }

            if ($clockOut !== null) {
                $previousClockOut = $clockOut;
            }
        }

        // Check weekly average limit (48 hours per 7-day period)
        $daysCount = max(1, $startDate->diffInDays($endDate) + 1);
        $weeksCount = max(1.0, $daysCount / 7.0);
        $weeklyAverage = $totalHoursWorked / $weeksCount;

        if ($weeklyAverage > 48.0) {
            $violations[] = [
                'rule' => 'IT_D_LGS_66_2003_ART_4',
                'message' => sprintf(
                    'Superamento del limite settimanale medio di lavoro: %.2f ore medie registrate (limite massimo legale 48 ore settimanali).',
                    $weeklyAverage
                ),
                'date' => $endDate->toDateString(),
            ];
        }

        return [
            'compliant' => empty($violations),
            'violations' => $violations,
            'stats' => [
                'total_hours' => round($totalHoursWorked, 2),
                'weekly_average_hours' => round($weeklyAverage, 2),
                'entries_count' => $entries->count(),
            ],
        ];
    }

    /**
     * Verify compliance with Swiss Labour Act (Loi sur le travail ArG / OLL 1):
     * - Maximum weekly hours: 45h (industrial, office, technical, large retail) or 50h (other) (ArG Art. 9)
     * - Minimum mandatory rest breaks based on daily working time (ArG Art. 15):
     *     - > 5.5 hours -> min 15 minutes break
     *     - > 7 hours   -> min 30 minutes break
     *     - > 9 hours   -> min 60 minutes break
     * - Minimum 11 consecutive hours daily rest period (ArG Art. 15a)
     *
     * @param  bool  $isIndustrialOrOffice  Defaults to true (45h weekly limit), false sets 50h limit.
     * @return array{
     *     compliant: bool,
     *     violations: array<int, array{rule: string, message: string, entry_id?: string, date: string}>,
     *     stats: array<string, mixed>
     * }
     */
    public function checkSwissArGCompliance(
        User $user,
        Carbon $startDate,
        Carbon $endDate,
        bool $isIndustrialOrOffice = true
    ): array {
        $maxWeeklyLimit = $isIndustrialOrOffice ? 45.0 : 50.0;

        $entries = TimeEntry::where('user_id', $user->id)
            ->whereBetween('clock_in', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->orderBy('clock_in', 'asc')
            ->get();

        $violations = [];
        $totalHoursWorked = 0.0;
        $previousClockOut = null;

        /** @var Collection<string, Collection<int, TimeEntry>> $entriesByDay */
        $entriesByDay = $entries->groupBy(fn (TimeEntry $entry): string => Carbon::parse($entry->clock_in)->toDateString());

        // Daily checks
        foreach ($entriesByDay as $dateStr => $dayEntries) {
            $dailyGrossMinutes = 0;
            $dailyBreakMinutes = 0;

            foreach ($dayEntries as $entry) {
                $clockIn = Carbon::parse($entry->clock_in);
                $clockOut = $entry->clock_out ? Carbon::parse($entry->clock_out) : null;

                if ($clockOut !== null) {
                    $dailyGrossMinutes += abs($clockOut->diffInMinutes($clockIn));
                    $dailyBreakMinutes += (int) ($entry->break_minutes ?? 0);
                }

                // Check 11 consecutive hours rest between shifts (ArG Art. 15a)
                if ($previousClockOut !== null) {
                    $restHours = abs($clockIn->diffInMinutes($previousClockOut)) / 60.0;
                    if ($restHours < 11.0) {
                        $violations[] = [
                            'rule' => 'CH_ARG_ART_15A_DAILY_REST',
                            'message' => sprintf(
                                'Tägliche Ruhezeit unterschritten: nur %.2f Stunden Ruhezeit (gesetzliches Minimum 11 aufeinanderfolgende Stunden gemäss ArG Art. 15a).',
                                $restHours
                            ),
                            'entry_id' => $entry->id,
                            'date' => $dateStr,
                        ];
                    }
                }

                if ($clockOut !== null) {
                    $previousClockOut = $clockOut;
                }
            }

            $dailyWorkingHours = ($dailyGrossMinutes - $dailyBreakMinutes) / 60.0;
            $totalHoursWorked += $dailyWorkingHours;

            // Mandatory Break checks (ArG Art. 15)
            // Daily working time > 9 hours -> 60 min break
            if ($dailyWorkingHours > 9.0 && $dailyBreakMinutes < 60) {
                $violations[] = [
                    'rule' => 'CH_ARG_ART_15_BREAK_60M',
                    'message' => sprintf(
                        'Pausenpflicht verletzt: Bei einer Arbeitszeit von %.2f Stunden ist eine Pause von mindestens 60 Minuten vorgeschrieben (erfasst: %d Min).',
                        $dailyWorkingHours,
                        $dailyBreakMinutes
                    ),
                    'date' => $dateStr,
                ];
            } elseif ($dailyWorkingHours > 7.0 && $dailyBreakMinutes < 30) {
                $violations[] = [
                    'rule' => 'CH_ARG_ART_15_BREAK_30M',
                    'message' => sprintf(
                        'Pausenpflicht verletzt: Bei einer Arbeitszeit von %.2f Stunden ist eine Pause von mindestens 30 Minuten vorgeschrieben (erfasst: %d Min).',
                        $dailyWorkingHours,
                        $dailyBreakMinutes
                    ),
                    'date' => $dateStr,
                ];
            } elseif ($dailyWorkingHours > 5.5 && $dailyBreakMinutes < 15) {
                $violations[] = [
                    'rule' => 'CH_ARG_ART_15_BREAK_15M',
                    'message' => sprintf(
                        'Pausenpflicht verletzt: Bei einer Arbeitszeit von %.2f Stunden ist eine Pause von mindestens 15 Minuten vorgeschrieben (erfasst: %d Min).',
                        $dailyWorkingHours,
                        $dailyBreakMinutes
                    ),
                    'date' => $dateStr,
                ];
            }
        }

        // Weekly Maximum Limit check (ArG Art. 9)
        $daysCount = max(1, $startDate->diffInDays($endDate) + 1);
        $weeksCount = max(1.0, $daysCount / 7.0);
        $weeklyAverage = $totalHoursWorked / $weeksCount;

        if ($weeklyAverage > $maxWeeklyLimit) {
            $violations[] = [
                'rule' => 'CH_ARG_ART_9_WEEKLY_LIMIT',
                'message' => sprintf(
                    'Höchstarbeitszeit gemäss ArG Art. 9 überschritten: wöchentlicher Schnitt von %.2f Stunden (gesetzliche Höchstarbeitszeit %.0f Stunden).',
                    $weeklyAverage,
                    $maxWeeklyLimit
                ),
                'date' => $endDate->toDateString(),
            ];
        }

        return [
            'compliant' => empty($violations),
            'violations' => $violations,
            'stats' => [
                'total_hours' => round($totalHoursWorked, 2),
                'weekly_average_hours' => round($weeklyAverage, 2),
                'max_weekly_limit' => $maxWeeklyLimit,
                'days_evaluated' => $daysCount,
            ],
        ];
    }
}
