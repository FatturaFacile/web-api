<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Queries;

use Carbon\CarbonImmutable;

/**
 * Ranks the employees that fit the skills (proficiency 1..5, valid safety certificates of Mod 099) and the net residual
 * availability required by a role over a window.
 */
final readonly class FindBestMatchingResourcesBySkillQuery
{
    /**
     * @param  list<array{skill_id: string, min_level: int}>  $requirements
     */
    public function __construct(
        public string $tenantId,
        public CarbonImmutable $startDate,
        public CarbonImmutable $endDate,
        public float $hoursPerDay,
        public array $requirements,
        public ?string $departmentId = null,
        public bool $requireValidCertificates = true,
        public bool $includeIneligible = false,
        public int $limit = 10,
    ) {}
}
