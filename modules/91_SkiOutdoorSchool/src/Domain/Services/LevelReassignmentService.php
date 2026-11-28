<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Services;

use Modules\SkiOutdoorSchool\Domain\Exceptions\SkiSchoolException;

/**
 * Domain rules of the level reassignment after the Monday on-snow evaluation: students move between the level groups of the same discipline
 * and week, only into groups that still take enrolments, and no group may end above its capacity once the whole batch is applied (a swap
 * between two full groups is therefore allowed). Pure: it validates a plan, the handler applies it.
 *
 * @phpstan-type GroupState array{code: string, capacity: int, enrolled: int, accepts: bool, discipline: string, week_start: string}
 * @phpstan-type Move array{enrollment_id: string, from_class_id: string, to_class_id: string}
 */
final class LevelReassignmentService
{
    /**
     * @param  array<string, GroupState>  $groups  keyed by class id, all the groups touched by the moves
     * @param  list<Move>  $moves
     * @return array<string, int> the occupancy of each group after the batch, keyed by class id
     *
     * @throws SkiSchoolException same_group, group_not_found, group_closed, group_mismatch, capacity_exceeded
     */
    public function validate(array $groups, array $moves): array
    {
        $occupancy = array_map(static fn (array $group): int => $group['enrolled'], $groups);
        $seen = [];

        foreach ($moves as $move) {
            $from = $groups[$move['from_class_id']] ?? throw SkiSchoolException::because('group_not_found', [], 404);
            $to = $groups[$move['to_class_id']] ?? throw SkiSchoolException::because('group_not_found', [], 404);

            if ($move['from_class_id'] === $move['to_class_id']) {
                throw SkiSchoolException::because('same_group');
            }

            if (isset($seen[$move['enrollment_id']])) {
                throw SkiSchoolException::because('duplicate_move');
            }
            $seen[$move['enrollment_id']] = true;

            if (! $to['accepts']) {
                throw SkiSchoolException::because('group_closed', ['group' => $to['code']]);
            }

            if ($from['discipline'] !== $to['discipline'] || $from['week_start'] !== $to['week_start']) {
                throw SkiSchoolException::because('group_mismatch', ['from' => $from['code'], 'to' => $to['code']]);
            }

            $occupancy[$move['from_class_id']]--;
            $occupancy[$move['to_class_id']]++;
        }

        foreach ($occupancy as $classId => $count) {
            if ($count > $groups[$classId]['capacity']) {
                throw SkiSchoolException::because('capacity_exceeded', ['group' => $groups[$classId]['code'], 'capacity' => $groups[$classId]['capacity']]);
            }
        }

        return $occupancy;
    }
}
