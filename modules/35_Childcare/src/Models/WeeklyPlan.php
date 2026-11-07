<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

use Carbon\CarbonImmutable;

/**
 * The planned weekly attendance of a child: per weekday (mon..fri, sat, sun) the times and the meals.
 *
 * @property string $child_id
 * @property CarbonImmutable $valid_from
 * @property ?CarbonImmutable $valid_to
 * @property array<string, array{from?: string, to?: string, meals?: list<string>}> $days
 */
final class WeeklyPlan extends ChildcareRecord
{
    /** @var list<string> */
    public const array Weekdays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** @var list<string> */
    public const array Meals = ['breakfast', 'lunch', 'snack', 'dinner'];

    protected $table = 'cc_weekly_plans';

    protected function casts(): array
    {
        return ['valid_from' => 'immutable_date', 'valid_to' => 'immutable_date', 'days' => 'array'];
    }

    /**
     * @return array{from?: string, to?: string, meals?: list<string>}|null
     */
    public function dayOf(CarbonImmutable $date): ?array
    {
        return $this->days[strtolower($date->format('D'))] ?? null;
    }
}
