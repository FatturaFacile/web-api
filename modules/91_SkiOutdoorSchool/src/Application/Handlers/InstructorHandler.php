<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\SkiOutdoorSchool\Application\Services\SchoolSettingsService;
use Modules\SkiOutdoorSchool\Domain\Enums\CourseStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\LessonStatus;
use Modules\SkiOutdoorSchool\Domain\Exceptions\SkiSchoolException;
use Modules\SkiOutdoorSchool\Domain\ValueObjects\TimeRange;
use Modules\SkiOutdoorSchool\Models\GroupCourse;
use Modules\SkiOutdoorSchool\Models\Instructor;
use Modules\SkiOutdoorSchool\Models\InstructorAvailability;
use Modules\SkiOutdoorSchool\Models\InstructorDiscipline;
use Modules\SkiOutdoorSchool\Models\InstructorLanguage;
use Modules\SkiOutdoorSchool\Models\SkiLesson;

/**
 * The instructor roster: profile, licence, spoken languages, disciplines, rate and retention rule, availability windows and blackouts.
 */
final class InstructorHandler
{
    public function __construct(private readonly SchoolSettingsService $settings) {}

    /**
     * @param  array<string, mixed>  $data  validated request data (languages: list of {code, proficiency}, disciplines: list of values)
     *
     * @throws SkiSchoolException instructor_not_found
     */
    public function save(array $data, ?string $id = null): Instructor
    {
        return DB::transaction(function () use ($data, $id): Instructor {
            $instructor = $id === null ? new Instructor : (Instructor::query()->whereKey($id)->lockForUpdate()->first() ?? throw SkiSchoolException::because('instructor_not_found', [], 404));
            $defaults = $this->settings->current();

            $instructor->fill([
                'contact_id' => $data['contact_id'] ?? null,
                'first_name' => (string) $data['first_name'],
                'last_name' => (string) $data['last_name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'license_type' => (string) $data['license_type'],
                'license_number' => $data['license_number'] ?? null,
                'license_valid_until' => $data['license_valid_until'] ?? null,
                'hourly_rate' => (float) ($data['hourly_rate'] ?? 0),
                'retention_mode' => (string) ($data['retention_mode'] ?? $defaults->default_retention_mode),
                'retention_value' => (float) ($data['retention_value'] ?? $defaults->default_retention_value),
                'collaboration_regime' => (string) ($data['collaboration_regime'] ?? 'FREELANCE'),
                'withholding_percent' => (float) ($data['withholding_percent'] ?? 0),
                'max_weekly_hours' => $data['max_weekly_hours'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'notes' => $data['notes'] ?? null,
            ]);
            $instructor->save();

            $this->syncLanguages($instructor, (array) ($data['languages'] ?? []));
            $this->syncDisciplines($instructor, (array) ($data['disciplines'] ?? []));

            return $instructor->load(['languages', 'disciplines'])->refresh();
        });
    }

    /**
     * @throws SkiSchoolException instructor_not_found, instructor_in_use
     */
    public function delete(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $instructor = Instructor::query()->whereKey($id)->lockForUpdate()->first() ?? throw SkiSchoolException::because('instructor_not_found', [], 404);
            $openLessons = SkiLesson::query()->where('instructor_id', $id)->whereIn('status', [LessonStatus::REQUESTED->value, LessonStatus::CONFIRMED->value])->exists();
            $openCourses = GroupCourse::query()->where('instructor_id', $id)->whereIn('status', [CourseStatus::PLANNED->value, CourseStatus::OPEN->value, CourseStatus::RUNNING->value])->exists();

            if ($openLessons || $openCourses) {
                throw SkiSchoolException::because('instructor_in_use');
            }

            $instructor->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data  slot_date, start_time, end_time, kind, note
     *
     * @throws SkiSchoolException instructor_not_found
     */
    public function addAvailability(string $instructorId, array $data): InstructorAvailability
    {
        $instructor = Instructor::query()->find($instructorId) ?? throw SkiSchoolException::because('instructor_not_found', [], 404);
        TimeRange::fromTimes((string) $data['start_time'], (string) $data['end_time']);

        return InstructorAvailability::query()->create([
            'tenant_id' => (string) $instructor->tenant_id,
            'instructor_id' => (string) $instructor->id,
            'slot_date' => (string) $data['slot_date'],
            'start_time' => (string) $data['start_time'],
            'end_time' => (string) $data['end_time'],
            'kind' => (string) ($data['kind'] ?? 'BLACKOUT'),
            'note' => $data['note'] ?? null,
        ]);
    }

    /**
     * @throws SkiSchoolException availability_not_found
     */
    public function removeAvailability(string $id): void
    {
        (InstructorAvailability::query()->find($id) ?? throw SkiSchoolException::because('availability_not_found', [], 404))->delete();
    }

    /**
     * @param  list<array<string, mixed>|string>  $languages
     */
    private function syncLanguages(Instructor $instructor, array $languages): void
    {
        InstructorLanguage::query()->where('instructor_id', $instructor->id)->get()->each->delete();
        $seen = [];

        foreach ($languages as $language) {
            $code = strtoupper((string) (is_array($language) ? $language['code'] : $language));

            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;

            InstructorLanguage::query()->create([
                'tenant_id' => (string) $instructor->tenant_id,
                'instructor_id' => (string) $instructor->id,
                'language_code' => $code,
                'proficiency' => is_array($language) ? (string) ($language['proficiency'] ?? 'FLUENT') : 'FLUENT',
            ]);
        }
    }

    /**
     * @param  list<string>  $disciplines
     */
    private function syncDisciplines(Instructor $instructor, array $disciplines): void
    {
        InstructorDiscipline::query()->where('instructor_id', $instructor->id)->get()->each->delete();

        foreach (array_unique(array_map('strval', $disciplines)) as $discipline) {
            InstructorDiscipline::query()->create([
                'tenant_id' => (string) $instructor->tenant_id,
                'instructor_id' => (string) $instructor->id,
                'discipline' => $discipline,
            ]);
        }
    }
}
