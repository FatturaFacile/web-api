<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService;

use Carbon\CarbonImmutable;
use Modules\ResourcePlanning\Application\Services\SkillCompliance;
use Modules\ResourcePlanning\Infrastructure\Models\EmployeeSkillProfile;
use Modules\ServiceManagement\Contracts\TechnicianSkillMatrixInterface;

/**
 * Reads the skill matrix of Mod 079 through its compliance service: a skill counts when the employee has a profile for it
 * that is valid on the day and, for skills that need one, a valid safety certificate (Mod 099).
 */
final readonly class ResourcePlanningSkillMatrixAdapter implements TechnicianSkillMatrixInterface
{
    public function __construct(private SkillCompliance $compliance) {}

    public function skillsOf(string $tenantId, ?string $employeeId, CarbonImmutable $on): array
    {
        if ($employeeId === null || $employeeId === '') {
            return [];
        }

        $skillIds = EmployeeSkillProfile::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('employee_id', $employeeId)->pluck('skill_id')->map(static fn ($id): string => (string) $id)->all();

        if ($skillIds === []) {
            return [];
        }

        $requirements = array_map(static fn (string $skillId): array => ['skill_id' => $skillId, 'min_level' => 1], $skillIds);
        $assessment = $this->compliance->assess([$employeeId], $requirements, $on, $on)[$employeeId] ?? null;

        if ($assessment === null) {
            return [];
        }

        $names = [];

        foreach ($assessment->matched as $match) {
            $name = mb_strtolower(trim($match['skill_name']));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
