<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * The texts written for the points of the mission report of a year.
 *
 * @property int $year
 * @property array<string, string> $answers
 */
final class MissionReport extends AssociationRecord
{
    protected $table = 'assoc_mission_reports';

    protected function casts(): array
    {
        return ['year' => 'integer', 'answers' => 'array'];
    }
}
