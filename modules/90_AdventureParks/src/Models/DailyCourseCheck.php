<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Daily opening check of the courses (UNI EN 15567-2): a signed checklist; it fails when any item is not in order.
 *
 * @property Carbon $check_date
 * @property string $checked_by_name
 * @property array<int, array{key: string, ok: bool, notes?: string|null}> $items
 * @property string $outcome
 * @property string|null $signature
 * @property string|null $notes
 */
final class DailyCourseCheck extends BaseEnterpriseModel
{
    protected $table = 'adv_daily_course_checks';

    protected function casts(): array
    {
        return [
            'check_date' => 'date',
            'items' => 'array',
        ];
    }

    /**
     * @param  array<int, array{key: string, ok: bool, notes?: string|null}>  $items
     */
    public static function outcomeOf(array $items): string
    {
        foreach ($items as $item) {
            if (! $item['ok']) {
                return 'FAIL';
            }
        }

        return 'PASS';
    }
}
