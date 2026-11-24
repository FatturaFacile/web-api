<?php

declare(strict_types=1);

namespace Modules\Projects\Wbs\Enums;

/**
 * Kind of a WBS node. Phases and deliverables normally group children; work packages carry the effort; milestones have no
 * duration.
 */
enum WbsNodeType: string
{
    case Phase = 'phase';
    case Deliverable = 'deliverable';
    case WorkPackage = 'work_package';
    case Milestone = 'milestone';

    /**
     * Accepts the snake_case value as well as the PascalCase name of the shared contract (`WorkPackage`).
     */
    public static function fromInput(string $value): ?self
    {
        $normalised = strtolower(str_replace(['_', '-', ' '], '', $value));

        foreach (self::cases() as $case) {
            if (strtolower(str_replace('_', '', $case->value)) === $normalised) {
                return $case;
            }
        }

        return null;
    }

    public function isMilestone(): bool
    {
        return $this === self::Milestone;
    }
}
