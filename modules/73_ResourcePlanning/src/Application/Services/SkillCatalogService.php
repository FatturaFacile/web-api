<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\ResourcePlanning\Domain\Contracts\CertificatePort;
use Modules\ResourcePlanning\Domain\Contracts\ResourceDirectoryPort;
use Modules\ResourcePlanning\Domain\Exceptions\ResourceNotFoundException;
use Modules\ResourcePlanning\Domain\Exceptions\ResourcePlanningException;
use Modules\ResourcePlanning\Domain\ValueObjects\CertificateValidity;
use Modules\ResourcePlanning\Infrastructure\Models\EmployeeSkillProfile;
use Modules\ResourcePlanning\Infrastructure\Models\Skill;

/**
 * The skill taxonomy and the skill matrix of the employees: create, change and delete both, and present a profile with the
 * state of the safety certificate its skill requires (Mod 099).
 */
final class SkillCatalogService
{
    public function __construct(private readonly ResourceDirectoryPort $directory, private readonly CertificatePort $certificates) {}

    /**
     * @return list<Skill>
     */
    public function skills(?string $category): array
    {
        return Skill::query()->when($category !== null, static fn (Builder $query) => $query->where('category', $category))->orderBy('category')->orderBy('skill_name')->get()->all();
    }

    /**
     * @param  array{category: string, skill_name: string, requires_certification?: bool, certification_code?: string|null}  $data
     */
    public function createSkill(array $data): Skill
    {
        $attributes = $this->skillAttributes($data);
        $deleted = Skill::onlyTrashed()->where('category', $attributes['category'])->where('skill_name', $attributes['skill_name'])->first();

        if ($deleted instanceof Skill) {
            // The deleted skill still holds the unique key: bring it back.
            $deleted->restore();
            $deleted->update($attributes);

            return $deleted->refresh();
        }

        return Skill::query()->create($attributes);
    }

    /**
     * @param  array{category: string, skill_name: string, requires_certification?: bool, certification_code?: string|null}  $data
     */
    public function updateSkill(string $id, array $data): Skill
    {
        $skill = Skill::query()->find($id) ?? throw new ResourceNotFoundException('skill_not_found', 'The skill does not exist.');
        $skill->update($this->skillAttributes($data));

        return $skill->refresh();
    }

    public function deleteSkill(string $id): void
    {
        $skill = Skill::query()->find($id) ?? throw new ResourceNotFoundException('skill_not_found', 'The skill does not exist.');

        DB::transaction(static function () use ($skill): void {
            EmployeeSkillProfile::query()->where('skill_id', $skill->getKey())->delete();
            $skill->delete();
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function profiles(?string $employeeId, ?string $skillId): array
    {
        $profiles = EmployeeSkillProfile::query()
            ->with('skill')
            ->when($employeeId !== null, static fn (Builder $query) => $query->where('employee_id', $employeeId))
            ->when($skillId !== null, static fn (Builder $query) => $query->where('skill_id', $skillId))
            ->get();

        return $this->present($profiles->all());
    }

    /**
     * Records the proficiency of an employee in a skill; an existing profile of the pair is updated.
     *
     * @param  array{employee_id: string, skill_id: string, proficiency_level: int, valid_until_date?: string|null}  $data
     * @return array<string, mixed>
     */
    public function upsertProfile(array $data): array
    {
        // A deleted profile of the pair still holds the unique key: bring it back instead of inserting a second row.
        $profile = EmployeeSkillProfile::withTrashed()->updateOrCreate(
            ['employee_id' => $data['employee_id'], 'skill_id' => $data['skill_id']],
            ['proficiency_level' => $data['proficiency_level'], 'valid_until_date' => $data['valid_until_date'] ?? null],
        );

        if ($profile->trashed()) {
            $profile->restore();
        }

        return $this->present([$profile->load('skill')])[0];
    }

    /**
     * @param  array{proficiency_level: int, valid_until_date?: string|null}  $data
     * @return array<string, mixed>
     */
    public function updateProfile(string $id, array $data): array
    {
        $profile = EmployeeSkillProfile::query()->find($id) ?? throw new ResourceNotFoundException('skill_profile_not_found', 'The skill profile does not exist.');
        $profile->update(['proficiency_level' => $data['proficiency_level'], 'valid_until_date' => $data['valid_until_date'] ?? null]);

        return $this->present([$profile->load('skill')])[0];
    }

    public function deleteProfile(string $id): void
    {
        $profile = EmployeeSkillProfile::query()->find($id) ?? throw new ResourceNotFoundException('skill_profile_not_found', 'The skill profile does not exist.');
        $profile->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function skillAttributes(array $data): array
    {
        $requires = (bool) ($data['requires_certification'] ?? false);
        $code = isset($data['certification_code']) && $data['certification_code'] !== '' ? (string) $data['certification_code'] : null;

        if ($requires && $code === null) {
            throw new ResourcePlanningException('certification_code_required', 'A skill that requires a certification needs its certificate code.');
        }

        return [
            'category' => $data['category'],
            'skill_name' => $data['skill_name'],
            'requires_certification' => $requires,
            'certification_code' => $requires ? $code : null,
        ];
    }

    /**
     * @param  list<EmployeeSkillProfile>  $profiles
     * @return list<array<string, mixed>>
     */
    private function present(array $profiles): array
    {
        $names = $this->directory->names(array_values(array_unique(array_map(static fn (EmployeeSkillProfile $profile): string => $profile->employee_id, $profiles))));
        $today = CarbonImmutable::today();

        /** @var array<string, array<string, CertificateValidity>> $certificates */
        $certificates = [];
        $byCode = [];

        foreach ($profiles as $profile) {
            $skill = $profile->skill;

            if ($skill instanceof Skill && $skill->requires_certification && $skill->certification_code !== null) {
                $byCode[$skill->certification_code][$profile->employee_id] = $profile->employee_id;
            }
        }

        foreach ($byCode as $code => $employeeIds) {
            $certificates[(string) $code] = $this->certificates->validity(array_values($employeeIds), (string) $code, $today, $today);
        }

        return array_map(function (EmployeeSkillProfile $profile) use ($names, $certificates, $today): array {
            $skill = $profile->skill;
            $code = $skill instanceof Skill && $skill->requires_certification ? $skill->certification_code : null;
            $certificate = $code === null ? null : ($certificates[$code][$profile->employee_id] ?? null);

            return [
                'id' => $profile->id,
                'employee_id' => $profile->employee_id,
                'employee_name' => $names[$profile->employee_id] ?? null,
                'skill_id' => $profile->skill_id,
                'skill_name' => $skill?->skill_name,
                'category' => $skill?->category,
                'proficiency_level' => $profile->proficiency_level,
                'valid_until_date' => $profile->valid_until_date?->format('Y-m-d'),
                'is_valid' => $profile->isValidOn($today),
                'requires_certification' => $skill->requires_certification ?? false,
                'certification_code' => $code,
                'certification_status' => $certificate === null ? 'not_required' : $certificate->status,
                'certification_valid_until' => $certificate?->validUntil?->format('Y-m-d'),
            ];
        }, $profiles);
    }
}
