<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AttendanceReportService
{
    /**
     * @return array{
     *     period: array{year: int, start_date: string, end_date: string},
     *     summary: array<string, int|float>,
     *     months: list<array{month: int, summary: array<string, int|float>}>
     * }
     */
    public function annualReport(User $user, int $year): array
    {
        $startOfYear = Carbon::create($year, 1, 1, 0, 0, 0);
        $startOfNextYear = $startOfYear->copy()->addYear();

        $entries = TimeEntry::where('user_id', $user->id)
            ->effectivePeriod($startOfYear, $startOfNextYear)
            ->orderByRaw('COALESCE(corrected_clock_in, clock_in) ASC')
            ->get();

        $entriesByMonth = $entries->groupBy(static fn (TimeEntry $entry): int => $entry->effectiveClockIn()->month);
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $months[] = [
                'month' => $month,
                'summary' => $this->summarize($entriesByMonth->get($month, collect())),
            ];
        }

        return [
            'period' => [
                'year' => $year,
                'start_date' => $startOfYear->toDateString(),
                'end_date' => $startOfNextYear->copy()->subDay()->toDateString(),
            ],
            'summary' => $this->summarize($entries),
            'months' => $months,
        ];
    }

    /**
     * @param  Collection<int, TimeEntry>  $entries
     * @return array{total_shifts: int, closed_shifts: int, open_shifts: int, gross_hours: float, break_hours: float, net_hours_worked: float}
     */
    public function summarize(Collection $entries): array
    {
        $totalGrossMinutes = 0.0;
        $totalBreakMinutes = 0;
        $totalNetMinutes = 0.0;
        $openShifts = 0;

        foreach ($entries as $entry) {
            $clockOut = $entry->effectiveClockOut();
            if ($clockOut === null) {
                $openShifts++;

                continue;
            }

            $grossMinutes = max(0.0, $entry->effectiveClockIn()->diffInMinutes($clockOut));
            $breakMinutes = max(0, $entry->effectiveBreakMinutes());

            $totalGrossMinutes += $grossMinutes;
            $totalBreakMinutes += $breakMinutes;
            $totalNetMinutes += max(0.0, $grossMinutes - $breakMinutes);
        }

        return [
            'total_shifts' => $entries->count(),
            'closed_shifts' => $entries->count() - $openShifts,
            'open_shifts' => $openShifts,
            'gross_hours' => round($totalGrossMinutes / 60.0, 2),
            'break_hours' => round($totalBreakMinutes / 60.0, 2),
            'net_hours_worked' => round($totalNetMinutes / 60.0, 2),
        ];
    }
}
