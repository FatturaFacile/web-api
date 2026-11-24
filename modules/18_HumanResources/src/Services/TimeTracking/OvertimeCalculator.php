<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\HumanResources\Models\OvertimeRule;
use Modules\HumanResources\Services\WorkingTimeCalculator;
use Modules\HumanResources\Support\Decimal;

/**
 * Applies the overtime rules of the company to the worked minutes of an employee (from the clock events and daily
 * records, through the working time engine). Rules of the same basis form tiers by threshold: each rule takes the
 * minutes between its threshold and the next one. Daily overtime is computed first; weekly rules count only the minutes
 * of the ISO week above their threshold that daily rules have not already counted. Without rules nothing is overtime.
 */
final readonly class OvertimeCalculator
{
    public function __construct(private WorkingTimeCalculator $workingTime) {}

    /**
     * @return array{employee_id: string, from: string, to: string, days: list<array<string, mixed>>, weeks: list<array<string, mixed>>, totals: list<array<string, mixed>>}
     */
    public function calculate(User $employee, string $from, string $to): array
    {
        $report = $this->workingTime->evaluate($employee, $from, $to);
        $totals = [];
        $days = [];
        $weekly = [];

        foreach ($report->days as $day) {
            $date = $day->date->format('Y-m-d');
            $rules = OvertimeRule::query()->effectiveOn($date)->get();
            $bands = $this->bands($rules->where('basis', OvertimeRule::BasisDaily)->values(), $day->workedMinutes);
            $dailyOvertime = array_sum(array_column($bands, 'minutes'));
            $week = $day->date->format('o-\WW');
            $weekly[$week] ??= ['worked' => 0, 'daily_overtime' => 0, 'rules' => $rules->where('basis', OvertimeRule::BasisWeekly)->values()];
            $weekly[$week]['worked'] += $day->workedMinutes;
            $weekly[$week]['daily_overtime'] += $dailyOvertime;

            foreach ($bands as $band) {
                $totals = $this->accumulate($totals, $band);
            }

            $days[] = ['date' => $date, 'worked_minutes' => $day->workedMinutes, 'overtime' => array_map(self::present(...), $bands)];
        }

        $weeks = [];

        foreach ($weekly as $week => $values) {
            $bands = $this->bands($values['rules'], $values['worked'], $values['daily_overtime']);

            foreach ($bands as $band) {
                $totals = $this->accumulate($totals, $band);
            }

            $weeks[] = ['week' => $week, 'worked_minutes' => $values['worked'], 'daily_overtime_minutes' => $values['daily_overtime'], 'overtime' => array_map(self::present(...), $bands)];
        }

        return [
            'employee_id' => (string) $employee->id,
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'weeks' => $weeks,
            'totals' => array_values(array_map(self::present(...), $totals)),
        ];
    }

    /**
     * The minutes of each tier above its threshold, minus minutes already counted by an earlier stage.
     *
     * @param  Collection<int, OvertimeRule>  $rules
     * @return list<array{rule: OvertimeRule, minutes: int}>
     */
    private function bands(Collection $rules, int $workedMinutes, int $alreadyCounted = 0): array
    {
        $sorted = $rules->sortBy('threshold_minutes')->values();
        $bands = [];
        $remainingCredit = $alreadyCounted;

        foreach ($sorted as $index => $rule) {
            $next = $sorted->get($index + 1);
            $ceiling = $next === null ? $workedMinutes : min($workedMinutes, (int) $next->threshold_minutes);
            $minutes = max(0, $ceiling - (int) $rule->threshold_minutes);
            $credited = min($minutes, $remainingCredit);
            $remainingCredit -= $credited;
            $minutes -= $credited;

            if ($minutes > 0) {
                $bands[] = ['rule' => $rule, 'minutes' => $minutes];
            }
        }

        return $bands;
    }

    /**
     * @param  array<string, array{rule: OvertimeRule, minutes: int}>  $totals
     * @param  array{rule: OvertimeRule, minutes: int}  $band
     * @return array<string, array{rule: OvertimeRule, minutes: int}>
     */
    private function accumulate(array $totals, array $band): array
    {
        $code = (string) $band['rule']->code;
        $totals[$code] = ['rule' => $band['rule'], 'minutes' => ($totals[$code]['minutes'] ?? 0) + $band['minutes']];

        return $totals;
    }

    /**
     * @param  array{rule: OvertimeRule, minutes: int}  $band
     * @return array<string, mixed>
     */
    private static function present(array $band): array
    {
        return [
            'rule_code' => $band['rule']->code,
            'basis' => $band['rule']->basis,
            'minutes' => $band['minutes'],
            'hours' => Decimal::round(Decimal::div((string) $band['minutes'], '60')),
            'premium_percent' => (string) $band['rule']->premium_percent,
            'wage_type_code' => $band['rule']->wage_type_code,
        ];
    }
}
