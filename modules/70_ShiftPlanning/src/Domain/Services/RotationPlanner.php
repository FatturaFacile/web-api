<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Services;

use Carbon\CarbonImmutable;
use Modules\ShiftPlanning\Domain\Enums\RotationPattern;

/**
 * Lays a rotation pattern over a period. Pure: it returns who works which template on which day and knows nothing about
 * contracts or labour law (the assignments are checked when they are stored).
 */
final class RotationPlanner
{
    /**
     * @return list<array{employee_index: int, date: CarbonImmutable, template_index: int}>
     */
    public function plan(RotationPattern $pattern, CarbonImmutable $start, CarbonImmutable $end, int $employeeCount, int $templateCount): array
    {
        if ($employeeCount < 1 || $templateCount < 1) {
            return [];
        }

        $plan = [];
        $firstMonday = $start->startOfWeek();

        for ($employee = 0; $employee < $employeeCount; $employee++) {
            for ($day = $start; $day <= $end; $day = $day->addDay()) {
                $dayIndex = (int) $start->diffInDays($day);

                if ($pattern === RotationPattern::FiveByTwo) {
                    $works = $day->isoWeekday() <= 5;
                    $block = intdiv((int) $firstMonday->diffInDays($day->startOfWeek()), 7);
                } else {
                    $position = $dayIndex + ($employee % 2) * intdiv($pattern->cycleDays(), 2);
                    $works = $position % $pattern->cycleDays() < $pattern->workDays();
                    $block = intdiv($position, $pattern->cycleDays());
                }

                if ($works) {
                    $plan[] = ['employee_index' => $employee, 'date' => $day, 'template_index' => ($block + $employee) % $templateCount];
                }
            }
        }

        return $plan;
    }
}
