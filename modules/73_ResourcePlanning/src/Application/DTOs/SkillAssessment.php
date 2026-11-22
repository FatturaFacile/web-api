<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\DTOs;

/**
 * How one employee meets the skill requirements of a booking: the skills that count and the reasons why the others do not.
 */
final readonly class SkillAssessment
{
    /**
     * @param  list<array{skill_id: string, skill_name: string, level: int, min_level: int, valid_until_date: string|null, certification_status: string}>  $matched
     * @param  list<array{code: string, skill_id: string|null, params: array<string, scalar|null>}>  $blockers
     * @param  float  $levelRatio  average of level / 5 over the matched skills (0 when nothing matches)
     */
    public function __construct(public array $matched, public array $blockers, public float $levelRatio) {}

    public function isEligible(): bool
    {
        return $this->blockers === [];
    }
}
