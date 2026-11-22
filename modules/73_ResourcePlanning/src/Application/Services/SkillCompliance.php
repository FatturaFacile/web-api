<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Services;

use Carbon\CarbonImmutable;
use Modules\ResourcePlanning\Application\DTOs\SkillAssessment;
use Modules\ResourcePlanning\Domain\Contracts\CertificatePort;
use Modules\ResourcePlanning\Domain\ValueObjects\CertificateValidity;
use Modules\ResourcePlanning\Infrastructure\Models\EmployeeSkillProfile;
use Modules\ResourcePlanning\Infrastructure\Models\Skill;

/**
 * Checks employees against skill requirements: the skill must be held at the minimum proficiency, valid through the whole
 * period, and a skill that requires a certification also needs a valid safety certificate of Mod 099.
 */
final class SkillCompliance
{
    public function __construct(private readonly CertificatePort $certificates) {}

    /**
     * @param  list<string>  $employeeIds
     * @param  list<array{skill_id: string, min_level: int}>  $requirements
     * @return array<string, SkillAssessment> keyed by employee id
     */
    public function assess(array $employeeIds, array $requirements, CarbonImmutable $start, CarbonImmutable $end, bool $requireValidCertificates = true): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $skillIds = array_values(array_unique(array_column($requirements, 'skill_id')));
        $skills = Skill::query()->whereIn('id', $skillIds)->get()->keyBy('id');
        $profiles = EmployeeSkillProfile::query()->whereIn('employee_id', $employeeIds)->whereIn('skill_id', $skillIds)->get()->groupBy('employee_id');

        /** @var array<string, array<string, CertificateValidity>> $certificates */
        $certificates = [];

        foreach ($skills as $skill) {
            if ($requireValidCertificates && $skill->requires_certification && $skill->certification_code !== null) {
                $certificates[(string) $skill->getKey()] = $this->certificates->validity($employeeIds, $skill->certification_code, $start, $end);
            }
        }

        $assessments = [];

        foreach ($employeeIds as $employeeId) {
            $held = ($profiles->get($employeeId) ?? collect())->keyBy('skill_id');
            $matched = [];
            $blockers = [];
            $ratios = [];

            foreach ($requirements as $requirement) {
                $skillId = $requirement['skill_id'];
                $skill = $skills->get($skillId);
                $profile = $held->get($skillId);
                $certificate = $certificates[$skillId][$employeeId] ?? null;

                if (! $skill instanceof Skill || ! $profile instanceof EmployeeSkillProfile) {
                    $blockers[] = ['code' => 'skill_missing', 'skill_id' => $skillId, 'params' => ['skill_name' => $skill?->skill_name]];

                    continue;
                }

                if ($profile->proficiency_level < $requirement['min_level']) {
                    $blockers[] = ['code' => 'proficiency_too_low', 'skill_id' => $skillId, 'params' => ['skill_name' => $skill->skill_name, 'level' => $profile->proficiency_level, 'min_level' => $requirement['min_level']]];

                    continue;
                }

                if (! $profile->isValidOn($end)) {
                    $blockers[] = ['code' => 'skill_expired', 'skill_id' => $skillId, 'params' => ['skill_name' => $skill->skill_name, 'valid_until_date' => $profile->valid_until_date?->format('Y-m-d')]];

                    continue;
                }

                if ($certificate !== null && ! $certificate->isValid()) {
                    $blockers[] = ['code' => $certificate->status === CertificateValidity::Expired ? 'certificate_expired' : 'certificate_missing', 'skill_id' => $skillId, 'params' => ['skill_name' => $skill->skill_name, 'certification_code' => $skill->certification_code, 'valid_until_date' => $certificate->validUntil?->format('Y-m-d')]];

                    continue;
                }

                $ratios[] = $profile->proficiency_level / 5;
                $matched[] = [
                    'skill_id' => $skillId,
                    'skill_name' => $skill->skill_name,
                    'level' => $profile->proficiency_level,
                    'min_level' => $requirement['min_level'],
                    'valid_until_date' => $profile->valid_until_date?->format('Y-m-d'),
                    'certification_status' => $certificate === null ? 'not_required' : $certificate->status,
                ];
            }

            $assessments[$employeeId] = new SkillAssessment($matched, $blockers, $ratios === [] ? 0.0 : array_sum($ratios) / count($ratios));
        }

        return $assessments;
    }

    /**
     * The non-blocking findings of a booking: skills the employee lacks or whose proficiency or certificate has lapsed.
     *
     * @param  list<string>  $skillIds
     * @return list<array{code: string, params: array<string, scalar|null>}>
     */
    public function warnings(string $employeeId, array $skillIds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($skillIds === []) {
            return [];
        }

        $requirements = array_map(static fn (string $skillId): array => ['skill_id' => $skillId, 'min_level' => 1], $skillIds);
        $assessment = $this->assess([$employeeId], $requirements, $start, $end)[$employeeId] ?? null;

        return $assessment === null ? [] : array_map(static fn (array $blocker): array => ['code' => $blocker['code'], 'params' => ['skill_id' => $blocker['skill_id'], ...$blocker['params']]], $assessment->blockers);
    }
}
