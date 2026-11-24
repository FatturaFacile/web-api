<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Time;

use Carbon\CarbonInterface;
use Modules\Manufacturing\Enums\TimeCategory;
use Modules\Manufacturing\Models\MfgMesTimeLog;

/**
 * Pure time engine of the MES: turns the timestamped intervals of a phase into setup, run, downtime, man time and
 * machine time. An open interval runs up to `$asOf`; an interval that ends before it starts counts zero; durations are
 * whole seconds of server (UTC) timestamps, so a reloaded client, a second tablet or a replayed offline queue always
 * computes the same numbers.
 */
final class PhaseTimeCalculator
{
    /**
     * Sums the intervals as of the given instant.
     *
     * @param  iterable<MfgMesTimeLog>  $logs
     */
    public function summarize(iterable $logs, CarbonInterface $asOf): PhaseTimeSummary
    {
        $setup = 0;
        $attended = 0;
        $unattended = 0;
        $downtime = 0;
        $manByOperator = [];
        $downtimeByReason = [];

        foreach ($logs as $log) {
            $seconds = $this->seconds($log, $asOf);
            if ($seconds === 0) {
                continue;
            }

            $operator = (string) ($log->operator_id ?? '');
            switch ($log->category) {
                case TimeCategory::Setup:
                    $setup += $seconds;
                    $manByOperator[$operator] = ($manByOperator[$operator] ?? 0) + $seconds;
                    break;
                case TimeCategory::Run:
                    if ($log->machine_attended) {
                        $attended += $seconds;
                        $manByOperator[$operator] = ($manByOperator[$operator] ?? 0) + $seconds;
                    } else {
                        $unattended += $seconds;
                    }
                    break;
                case TimeCategory::Suspension:
                    $downtime += $seconds;
                    $reason = $log->suspension_reason !== null ? $log->suspension_reason->value : 'other';
                    $downtimeByReason[$reason] = ($downtimeByReason[$reason] ?? 0) + $seconds;
                    break;
            }
        }

        return new PhaseTimeSummary($setup, $attended, $unattended, $downtime, $manByOperator, $downtimeByReason);
    }

    /**
     * Whole seconds of one interval, an open one measured up to the given instant, never negative.
     */
    private function seconds(MfgMesTimeLog $log, CarbonInterface $asOf): int
    {
        $end = $log->ended_at ?? $asOf;
        $diff = $log->started_at->diffInSeconds($end, false);

        return $diff > 0 ? (int) floor($diff) : 0;
    }
}
